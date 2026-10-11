<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\SendAnnouncementEmailJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Super-admin platform announcements: compose an essential, operational email
 * about the Platform and send it to a chosen set of Company_Users — by role,
 * optionally scoped to a single Company.
 *
 * These are service communications about the Platform itself, not marketing, so
 * there is no consent/unsubscribe gate; the audience is chosen explicitly by
 * the operator. Two audiences are always excluded: Super_Admin accounts (this
 * targets clients, not staff) and DISABLED accounts (a disabled user is never
 * contacted).
 *
 * The body is authored as Markdown and rendered to SAFE HTML once at send time
 * (`html_input => 'escape'`, mirroring the Trust & Legal centre), so raw HTML in
 * the source is neutralised and no untrusted markup reaches recipients. Delivery
 * is fanned out to one small, independently retryable {@see SendAnnouncementEmailJob}
 * per recipient on the DB queue, so a large audience never blocks and one flaky
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

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Show the compose form: subject, Markdown body, the roles to target and an
     * optional single-Company scope.
     */
    public function create(): View
    {
        return view('admin.announcements.create', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'roles' => User::ROLES,
        ]);
    }

    /**
     * Validate, resolve the audience, render the body to safe HTML, and queue
     * one delivery job per recipient. A send over the confirmation threshold is
     * bounced back to the form (with the composed content preserved) for an
     * explicit confirmation before it proceeds.
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

        // Render the Markdown body to safe HTML ONCE (raw HTML escaped), then
        // carry that trusted HTML to every delivery job.
        $bodyHtml = Str::markdown($validated['body'], [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        foreach ($recipients as $recipient) {
            SendAnnouncementEmailJob::dispatch(
                (int) $recipient->id,
                $validated['subject'],
                $bodyHtml,
            );
        }

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
            ->with('status', __('Announcement queued for :count recipient(s).', [
                'count' => $recipients->count(),
            ]));
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
