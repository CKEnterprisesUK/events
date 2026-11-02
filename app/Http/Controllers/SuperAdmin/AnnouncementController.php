<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\SendAnnouncementEmailJob;
use App\Mail\PlatformAnnouncementMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AnnouncementRenderer;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Super-admin platform announcements: compose an essential, operational email
 * about the Platform and send it to a chosen set of Company_Users — by role,
 * optionally scoped to a single Company.
 *
 * These are service communications about the Platform itself, not marketing, so
 * there is no consent/unsubscribe gate; the audience is chosen explicitly by
 * the operator. Two audiences are always excluded from the client send:
 * Super_Admin accounts (this targets clients, not staff) and DISABLED accounts
 * (a disabled user is never contacted). The SENDING super admin always receives
 * their own copy of every send, so platform staff keep a record of exactly what
 * went out.
 *
 * The body is authored as Markdown with personalisation placeholders
 * (`{{name}}`, `{{company}}`, `{{platform}}`, `{{dashboard_url}}`) and rendered
 * to SAFE HTML per recipient at delivery ({@see AnnouncementRenderer}): raw HTML
 * is escaped so no untrusted markup reaches recipients. Delivery is fanned out
 * to one small, independently retryable {@see SendAnnouncementEmailJob} per
 * recipient on the DB queue, so a large audience never blocks and one flaky
 * address never fails the batch. A large send (over {@see self::CONFIRM_THRESHOLD}
 * recipients) requires an explicit second-step confirmation.
 */
class AnnouncementController extends Controller
{
    /**
     * Recipient count above which the operator must explicitly confirm before
     * the send proceeds, guarding against an accidental blast to a huge
     * audience.
     */
    public const CONFIRM_THRESHOLD = 500;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AnnouncementRenderer $renderer,
    ) {}

    /**
     * Show the compose form: subject, Markdown body, the roles to target and an
     * optional single-Company scope.
     */
    public function create(): View
    {
        return view('admin.announcements.create', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'roles' => User::ROLES,
            'placeholders' => AnnouncementRenderer::PLACEHOLDERS,
        ]);
    }

    /**
     * Render the composed Markdown body to the exact safe HTML an announcement
     * email uses, with illustrative sample placeholder values, and return it
     * for the compose form's live preview. The preview is a true
     * what-you-see-is-what-you-send of the body: it shares the renderer with
     * real delivery.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        $sampleName = 'Alex Taylor';

        $bodyHtml = $this->renderer->renderWithValues($validated['body'] ?? '', [
            'name' => $sampleName,
            'company' => 'Example Events Ltd',
            'platform' => (string) config('app.name'),
            'dashboard_url' => route('dashboard.index'),
        ]);

        // Render the body into the REAL email template (header, greeting,
        // footer) so the preview is exactly what a recipient receives, not just
        // the body fragment.
        $html = view('emails.announcement', [
            'subjectLine' => (string) $request->input('subject', 'Subject preview'),
            'bodyHtml' => $bodyHtml,
            'recipientName' => $sampleName,
            'appName' => (string) config('app.name'),
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * Validate, resolve the audience, queue one delivery job per recipient, and
     * send the sending super admin their own copy. A send over the confirmation
     * threshold is bounced back to the form (with the composed content
     * preserved) for an explicit confirmation before it proceeds.
     */
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(User::ROLES)],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $roles = array_values(array_unique($validated['roles']));
        $companyId = $validated['company_id'] ?? null;

        // Resolve the audience: active (non-disabled), non-super-admin users in
        // the chosen roles, optionally scoped to one Company. Fetch id + name +
        // email — that is all a delivery job needs.
        $recipients = $this->audienceQuery($roles, $companyId)->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['roles' => __('No active users match that audience.')]);
        }

        // Large-send guard: require an explicit confirmation before blasting a
        // big audience. The form re-submits with `confirm=1` after the operator
        // acknowledges the count.
        if ($recipients->count() > self::CONFIRM_THRESHOLD && ! $request->boolean('confirm')) {
            return back()
                ->withInput()
                ->with('confirm_count', $recipients->count());
        }

        // Carry the RAW Markdown body to each job; it is rendered to safe HTML
        // per recipient so the personalisation placeholders resolve to that
        // recipient's own name/company.
        foreach ($recipients as $recipient) {
            SendAnnouncementEmailJob::dispatch(
                (int) $recipient->id,
                $validated['subject'],
                $validated['body'],
            );
        }

        $this->sendOperatorCopy($validated['subject'], $validated['body']);

        $this->audit->record(
            action: AuditLog::ANNOUNCEMENT_SENT,
            summary: 'Sent a platform announcement to '.$recipients->count().' user(s)',
            context: [
                'subject' => $validated['subject'],
                'roles' => $roles,
                'company_id' => $companyId,
                'recipients' => $recipients->count(),
            ],
            companyId: $companyId !== null ? (int) $companyId : null,
        );

        return redirect()
            ->route('admin.announcements.create')
            ->with('status', __('Announcement queued for :count recipient(s). A copy has been sent to you.', [
                'count' => $recipients->count(),
            ]));
    }

    /**
     * Send the sending super admin their own copy of the announcement so
     * platform staff always have a record of what went out. The copy uses
     * sample placeholder values (there is no single "recipient" for a broadcast)
     * and is sent directly rather than through the client delivery job, which
     * deliberately excludes super admins.
     */
    private function sendOperatorCopy(string $subject, string $body): void
    {
        $operator = Auth::user();

        if ($operator === null || $operator->email === null) {
            return;
        }

        $html = $this->renderer->renderWithValues($body, [
            'name' => $operator->name,
            'company' => 'Example Events Ltd',
            'platform' => (string) config('app.name'),
            'dashboard_url' => route('dashboard.index'),
        ]);

        Mail::to($operator->email)->send(new PlatformAnnouncementMail(
            subjectLine: '[Copy] '.$subject,
            bodyHtml: $html,
            recipientName: $operator->name,
        ));
    }

    /**
     * The audience query: active, non-super-admin users holding one of the
     * chosen roles, optionally within a single Company. Shared by the count
     * guard and the send.
     *
     * @param  list<string>  $roles
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function audienceQuery(array $roles, ?int $companyId)
    {
        return User::query()
            ->where('is_super_admin', false)
            ->whereNull('disabled_at')
            ->whereIn('role', $roles)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId));
    }
}
