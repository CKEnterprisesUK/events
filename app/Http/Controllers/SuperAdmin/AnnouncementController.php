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
 * ## Compose → review → send
 *
 * Sending is a deliberate TWO-STEP flow: an announcement can never be sent
 * straight from the compose form. The operator composes, then {@see review()}
 * validates the input, resolves the exact audience and renders the REAL email,
 * and shows a review page. Only a submit FROM that review page (carrying the
 * reviewed content and an acknowledgement) reaches {@see send()}, which
 * re-validates and requires the `reviewed` acknowledgement before it queues
 * anything. This forces a human to look at the rendered email, and the exact
 * audience, before a single message goes out.
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
 * address never fails the batch.
 */
class AnnouncementController extends Controller
{
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
     * "Back to edit" from the review page: flash the reviewed content back as
     * old input and return to the compose form, so no edits are lost. Posting
     * (not a GET link) is what lets the content ride back into `old()`.
     */
    public function edit(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.announcements.create')
            ->withInput($request->only(['subject', 'body', 'roles', 'company_id']));
    }

    /**
     * Step 2 of the flow: validate the composed announcement, resolve the exact
     * audience, render the REAL email (template chrome included, placeholders
     * shown with sample values), and show the review page.
     *
     * Nothing is sent here. The review page re-submits the same content to
     * {@see send()} with a `reviewed` acknowledgement, which is the only way a
     * send proceeds. If the audience is empty the operator is bounced straight
     * back to compose with the content preserved.
     */
    public function review(Request $request): View|RedirectResponse
    {
        $validated = $this->validateCompose($request);

        $roles = array_values(array_unique($validated['roles']));
        $companyId = $validated['company_id'] ?? null;

        $recipientCount = $this->audienceQuery($roles, $companyId)->count();

        if ($recipientCount === 0) {
            return redirect()
                ->route('admin.announcements.create')
                ->withInput()
                ->withErrors(['roles' => __('No active users match that audience.')]);
        }

        // Render the body with sample placeholder values into the real email
        // template, so the review is exactly what a recipient receives.
        $previewHtml = $this->renderEmail(
            $validated['subject'],
            $validated['body'],
            sampleName: 'Alex Taylor',
            sampleCompany: $this->sampleCompanyName($companyId),
        );

        $company = $companyId !== null
            ? Company::query()->find($companyId, ['id', 'name'])
            : null;

        return view('admin.announcements.review', [
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'roles' => $roles,
            'companyId' => $companyId,
            'companyName' => $company?->name,
            'recipientCount' => $recipientCount,
            'previewHtml' => $previewHtml,
        ]);
    }

    /**
     * Final step: re-validate, require the `reviewed` acknowledgement from the
     * review page, queue one delivery job per recipient, and send the sending
     * super admin their own copy. A direct hit without `reviewed` is sent back
     * through the review step rather than sending.
     */
    public function send(Request $request): RedirectResponse
    {
        $validated = $this->validateCompose($request);

        // A send only proceeds from the review step. Without the acknowledgement
        // (e.g. a replayed/bookmarked POST), fall back to showing the review.
        if (! $request->boolean('reviewed')) {
            return redirect()
                ->route('admin.announcements.create')
                ->withInput()
                ->withErrors(['body' => __('Please review the announcement before sending.')]);
        }

        $roles = array_values(array_unique($validated['roles']));
        $companyId = $validated['company_id'] ?? null;

        $recipients = $this->audienceQuery($roles, $companyId)->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return redirect()
                ->route('admin.announcements.create')
                ->withInput()
                ->withErrors(['roles' => __('No active users match that audience.')]);
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

        $this->sendOperatorCopy($validated['subject'], $validated['body'], $companyId);

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
     * The shared validation rules for both the review and send steps, so the
     * two steps can never diverge on what they accept.
     *
     * @return array{subject: string, body: string, roles: list<string>, company_id: int|null}
     */
    private function validateCompose(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(User::ROLES)],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
        ]);
    }

    /**
     * Render a Markdown body into the real announcement email template with the
     * given sample placeholder values. Shared by the review page and the
     * operator's own copy so both match live delivery exactly.
     */
    private function renderEmail(string $subject, string $body, string $sampleName, string $sampleCompany): string
    {
        $bodyHtml = $this->renderer->renderWithValues($body, [
            'name' => $sampleName,
            'company' => $sampleCompany,
            'platform' => (string) config('app.name'),
            'dashboard_url' => route('dashboard.home'),
        ]);

        return view('emails.announcement', [
            'subjectLine' => $subject,
            'bodyHtml' => $bodyHtml,
            'recipientName' => $sampleName,
            'appName' => (string) config('app.name'),
        ])->render();
    }

    /**
     * The sample company name used in the review/preview. When the send is
     * scoped to a single Company, that Company's real name is used so the
     * preview is faithful; otherwise a generic sample stands in for the mixed
     * audience.
     */
    private function sampleCompanyName(?int $companyId): string
    {
        if ($companyId !== null) {
            $name = Company::query()->whereKey($companyId)->value('name');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return 'Example Events Ltd';
    }

    /**
     * Send the sending super admin their own copy of the announcement so
     * platform staff always have a record of what went out. The copy uses
     * sample placeholder values (there is no single "recipient" for a broadcast)
     * and is sent directly rather than through the client delivery job, which
     * deliberately excludes super admins.
     */
    private function sendOperatorCopy(string $subject, string $body, ?int $companyId): void
    {
        $operator = Auth::user();

        if ($operator === null || $operator->email === null) {
            return;
        }

        $html = $this->renderEmail(
            $subject,
            $body,
            sampleName: $operator->name,
            sampleCompany: $this->sampleCompanyName($companyId),
        );

        Mail::to($operator->email)->send(new PlatformAnnouncementMail(
            subjectLine: '[Copy] '.$subject,
            bodyHtml: $html,
            recipientName: $operator->name,
        ));
    }

    /**
     * The audience query: active, non-super-admin users holding one of the
     * chosen roles, optionally within a single Company. Shared by the review
     * count and the send.
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
