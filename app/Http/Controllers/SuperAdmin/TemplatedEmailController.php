<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Jobs\SendAnnouncementEmailJob;
use App\Mail\PlatformAnnouncementMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Services\AnnouncementRenderer;
use App\Services\AuditLogger;
use App\Services\EventReportService;
use App\Services\TemplatedEmailLibrary;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Super-admin manual CLIENT emails built from prebuilt templates.
 *
 * This is a sibling of {@see AnnouncementController}: a super admin manually
 * triggers an email to a chosen set of clients (by role, optionally scoped to a
 * single Company) and the exact same safety applies — compose → REVIEW → send,
 * disabled accounts and super admins are always excluded, and the sending super
 * admin always gets their own copy. Delivery reuses the announcement pipeline
 * ({@see SendAnnouncementEmailJob} + {@see PlatformAnnouncementMail} +
 * {@see AnnouncementRenderer}), so personalisation placeholders, safe-HTML
 * rendering and the queued per-recipient fan-out are identical.
 *
 * The one difference from a free-form announcement is the STARTING CONTENT:
 * rather than authoring the body, the operator picks a template from
 * {@see TemplatedEmailLibrary}:
 *
 *   - "Pre-event information" — canned guidance on inviting scanner staff and
 *     running door scanning.
 *   - "Sales report for an event" — a per-event summary BUILT from the live
 *     {@see EventReportService} figures for a chosen Event, so the
 *     numbers are the platform's single accounting source of truth.
 *
 * The chosen subject/body are still fully editable before sending, and the
 * review step renders the REAL email so the operator sees exactly what goes out.
 */
class TemplatedEmailController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AnnouncementRenderer $renderer,
        private readonly TemplatedEmailLibrary $library,
    ) {}

    /**
     * Step 0: pick a template (and, for the sales report, the Event it reports
     * on). This seeds the compose form with the template's subject + body.
     */
    public function create(Request $request): View
    {
        $template = (string) $request->query('template', '');
        $eventId = $request->integer('event_id') ?: null;

        $event = null;
        if ($eventId !== null) {
            // Cross-tenant: the super admin surface is not company-scoped, so
            // read the Event without the tenant global scope and eager-load the
            // owning Company for currency + default audience.
            $event = Event::withoutGlobalScopes()->with('company:id,name,currency')->find($eventId);
        }

        $hasTemplate = $this->library->exists($template);

        return view('admin.templated-emails.create', [
            'templates' => TemplatedEmailLibrary::TEMPLATES,
            'selectedTemplate' => $hasTemplate ? $template : null,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'events' => $this->eventOptions(),
            'roles' => User::ROLES,
            'event' => $event,
            // When a template is chosen, pre-fill subject/body unless the
            // operator has already typed something (old input wins).
            'defaultSubject' => $hasTemplate ? $this->library->defaultSubject($template, $event) : null,
            'defaultBody' => $hasTemplate ? $this->library->defaultBody($template, $event) : null,
            // Sensible default audience: for a sales report scoped to an event,
            // default the company to that event's owner.
            'defaultCompanyId' => $event?->company_id,
        ]);
    }

    /**
     * "Back to edit" from review: flash the reviewed content back as old input
     * and reopen compose so nothing is lost.
     */
    public function edit(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.templated-emails.create', $request->only(['template', 'event_id']))
            ->withInput($request->only(['template', 'event_id', 'subject', 'body', 'roles', 'company_id']));
    }

    /**
     * Step 2: validate, resolve the audience, render the REAL email and show the
     * review page. Nothing is sent here.
     */
    public function review(Request $request): View|RedirectResponse
    {
        $validated = $this->validateCompose($request);

        $roles = array_values(array_unique($validated['roles']));
        $companyId = $validated['company_id'] ?? null;

        $recipientCount = $this->audienceQuery($roles, $companyId)->count();

        if ($recipientCount === 0) {
            return redirect()
                ->route('admin.templated-emails.create', $request->only(['template', 'event_id']))
                ->withInput()
                ->withErrors(['roles' => __('No active users match that audience.')]);
        }

        $previewHtml = $this->renderEmail(
            $validated['subject'],
            $validated['body'],
            sampleName: 'Alex Taylor',
            sampleCompany: $this->sampleCompanyName($companyId),
        );

        $company = $companyId !== null
            ? Company::query()->find($companyId, ['id', 'name'])
            : null;

        return view('admin.templated-emails.review', [
            'template' => $validated['template'],
            'templateLabel' => TemplatedEmailLibrary::label($validated['template']),
            'eventId' => $validated['event_id'] ?? null,
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
     * Final step: re-validate, require the `reviewed` acknowledgement, queue one
     * delivery job per recipient, and send the operator their own copy.
     */
    public function send(Request $request): RedirectResponse
    {
        $validated = $this->validateCompose($request);

        if (! $request->boolean('reviewed')) {
            return redirect()
                ->route('admin.templated-emails.create', $request->only(['template', 'event_id']))
                ->withInput()
                ->withErrors(['body' => __('Please review the email before sending.')]);
        }

        $roles = array_values(array_unique($validated['roles']));
        $companyId = $validated['company_id'] ?? null;

        $recipients = $this->audienceQuery($roles, $companyId)->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return redirect()
                ->route('admin.templated-emails.create', $request->only(['template', 'event_id']))
                ->withInput()
                ->withErrors(['roles' => __('No active users match that audience.')]);
        }

        foreach ($recipients as $recipient) {
            SendAnnouncementEmailJob::dispatch(
                (int) $recipient->id,
                $validated['subject'],
                $validated['body'],
            );
        }

        $this->sendOperatorCopy($validated['subject'], $validated['body'], $companyId);

        $this->audit->record(
            action: $validated['template'] === TemplatedEmailLibrary::SALES_REPORT
                ? AuditLog::SALES_REPORT_EMAIL_SENT
                : AuditLog::PRE_EVENT_INFO_SENT,
            summary: 'Sent the "'.TemplatedEmailLibrary::label($validated['template'])
                .'" email to '.$recipients->count().' user(s)',
            context: [
                'template' => $validated['template'],
                'event_id' => $validated['event_id'] ?? null,
                'subject' => $validated['subject'],
                'roles' => $roles,
                'company_id' => $companyId,
                'recipients' => $recipients->count(),
            ],
            companyId: $companyId !== null ? (int) $companyId : null,
        );

        return redirect()
            ->route('admin.templated-emails.create')
            ->with('status', __(':label queued for :count recipient(s). A copy has been sent to you.', [
                'label' => TemplatedEmailLibrary::label($validated['template']),
                'count' => $recipients->count(),
            ]));
    }

    /**
     * Shared validation for review and send, so the two steps never diverge.
     *
     * @return array{template: string, event_id: int|null, subject: string, body: string, roles: list<string>, company_id: int|null}
     */
    private function validateCompose(Request $request): array
    {
        return $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys(TemplatedEmailLibrary::TEMPLATES))],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(User::ROLES)],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
        ]);
    }

    /**
     * Render a Markdown body into the real announcement email template with the
     * given sample placeholder values, matching live delivery exactly.
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
     * The sample company name used in the preview: the scoped Company's real
     * name when the send targets one Company, else a generic sample.
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
     * Send the sending super admin their own copy, direct (not via the client
     * job, which excludes super admins), with sample placeholder values.
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
     * chosen roles, optionally within a single Company.
     *
     * @param  list<string>  $roles
     * @return Builder<User>
     */
    private function audienceQuery(array $roles, ?int $companyId)
    {
        return User::query()
            ->where('is_super_admin', false)
            ->whereNull('disabled_at')
            ->whereIn('role', $roles)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId));
    }

    /**
     * Events for the template picker's Event select, newest first, labelled with
     * their owning Company. Cross-tenant (no company scope on the super-admin
     * surface).
     *
     * @return Collection<int, Event>
     */
    private function eventOptions()
    {
        return Event::withoutGlobalScopes()
            ->with('company:id,name')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'company_id', 'name', 'starts_at']);
    }
}
