<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\AuditLogger;
use App\Services\Mail\Graph\GraphMailDiagnostics;
use App\Services\Mail\Graph\GraphMailException;
use App\Services\Mail\MailTransportResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Super-admin platform settings surface. Today it hosts mail troubleshooting:
 * a read-only view of the effective mail configuration plus a tool to send a
 * diagnostic test email to any address, so a Super_Admin can confirm the
 * configured transport actually delivers.
 *
 * This surface is not tenant-scoped — a Super_Admin operates across the whole
 * Platform. The mail *credentials* still live in the environment / config, but
 * the choice of outbound transport (SMTP vs Microsoft Graph) is persisted on
 * the single `platform_settings` row and toggled here. The test send is
 * dispatched synchronously so the admin sees an immediate success or the
 * failure reason.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MailTransportResolver $transport,
        private readonly GraphMailDiagnostics $graphDiagnostics,
    ) {}

    /**
     * Show the effective mail configuration (read-only diagnostics), the
     * outbound-transport toggle, the masked Graph configuration, and the
     * test-email form.
     */
    public function index(Request $request): View
    {
        return view('admin.settings.index', [
            'mail' => $this->mailDiagnostics(),
            'defaultTestEmail' => $request->user()?->email,
            'selectedTransport' => $this->transport->selectedTransport(),
            'activeMailer' => $this->transport->activeMailer(),
            'graphConfigured' => $this->transport->graphConfigured(),
            'graph' => $this->graphDiagnostics->configSummary(),
        ]);
    }

    /**
     * Run a live, read-only Microsoft Graph connectivity probe: confirm the
     * config is complete and attempt to acquire an application token. Reports a
     * clear success or a stage-specific failure hint, so the Super_Admin can
     * tell an authentication problem (token stage) apart from a mailbox
     * send-permission problem (which only shows on an actual send/403). Sends no
     * mail and changes no state.
     */
    public function runGraphDiagnostics(): RedirectResponse
    {
        $result = $this->graphDiagnostics->probe();

        $redirect = redirect()->route('admin.settings.index');

        if ($result->ok) {
            return $redirect->with('status', __('Graph diagnostics: :message', [
                'message' => $result->message,
            ]));
        }

        $message = $result->hint !== null
            ? $result->message.' — '.$result->hint
            : $result->message;

        return $redirect->with('error', __('Graph diagnostics failed: :message', [
            'message' => $message,
        ]));
    }

    /**
     * Clear the cached Microsoft Graph application token so the next send/probe
     * re-authenticates. Useful right after granting admin consent or rotating
     * the client secret in Azure — a token issued before that change can sit in
     * cache for up to ~an hour and keep reflecting the old state. Gives the
     * Super_Admin a shell-free way to flush it (the app runs on cPanel, no SSH).
     */
    public function clearGraphToken(): RedirectResponse
    {
        $this->graphDiagnostics->forgetCachedToken();

        return redirect()
            ->route('admin.settings.index')
            ->with('status', __('Cleared the cached Microsoft Graph token. The next test email or '
                .'diagnostics run will fetch a fresh token from Microsoft.'));
    }

    /**
     * Persist the Platform-wide outbound-mail transport (SMTP or Microsoft
     * Graph). Graph only actually takes effect once its environment credentials
     * are present — otherwise the app keeps sending via SMTP even after this is
     * set to `graph` — so switching is always safe. Records an audit entry on
     * the platform trail and clears the resolver cache so the change is picked
     * up on the next send.
     */
    public function updateMailTransport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_transport' => ['required', Rule::in(PlatformSetting::MAIL_TRANSPORTS)],
        ]);

        $setting = PlatformSetting::current();
        $previous = $setting->mail_transport;
        $setting->mail_transport = $validated['mail_transport'];
        $setting->save();

        // Refresh the short-lived cache so the new choice is observed at once.
        $this->transport->forget();

        // Platform-level change (no tenant): recorded with a null company_id so
        // it appears only on the super-admin trail.
        $this->audit->record(
            action: AuditLog::MAIL_TRANSPORT_CHANGED,
            summary: 'Changed the mail transport to '.$validated['mail_transport'],
            context: [
                'from' => $previous,
                'to' => $validated['mail_transport'],
            ],
        );

        $message = $validated['mail_transport'] === PlatformSetting::MAIL_TRANSPORT_GRAPH && ! $this->transport->graphConfigured()
            ? __('Mail transport set to Microsoft Graph, but Graph is not configured yet — mail will keep sending via SMTP until the Graph credentials are added to the environment.')
            : __('Mail transport updated.');

        return redirect()
            ->route('admin.settings.index')
            ->with('status', $message);
    }

    /**
     * Send a diagnostic test email to the given address through the configured
     * mailer, synchronously. Surfaces the transport error verbatim on failure
     * so the Super_Admin can troubleshoot (bad host, auth, TLS, etc.).
     */
    public function sendTest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $mailerName = (string) config('mail.default');

        try {
            Mail::to($validated['email'])->send(
                new TestMail(recipient: $validated['email'], mailerName: $mailerName)
            );
        } catch (Throwable $e) {
            return redirect()
                ->route('admin.settings.index')
                ->with('error', __('Test email failed: :message', [
                    'message' => $this->explainFailure($e),
                ]));
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('status', __('Test email sent to :email via the ":mailer" mailer.', [
                'email' => $validated['email'],
                'mailer' => $mailerName,
            ]));
    }

    /**
     * Turn a send failure into an operator-facing message. For a Graph failure
     * we append the stage-specific hint (e.g. a 403 points at the Mail.Send
     * application permission / Application Access Policy) so the Super_Admin
     * gets the next step inline rather than just the raw Graph text. Laravel may
     * wrap the transport error in a TransportException, so we unwrap one level.
     */
    private function explainFailure(Throwable $e): string
    {
        $graph = $e instanceof GraphMailException
            ? $e
            : ($e->getPrevious() instanceof GraphMailException ? $e->getPrevious() : null);

        if ($graph !== null && $graph->hint() !== null) {
            return $graph->getMessage().' — '.$graph->hint();
        }

        return $e->getMessage();
    }

    /**
     * The effective mail settings worth surfacing for troubleshooting. Read
     * from config so it reflects whatever the environment resolved to. Secrets
     * (password) are reported as a presence flag, never the value.
     *
     * @return array<string, string>
     */
    private function mailDiagnostics(): array
    {
        $default = (string) config('mail.default');
        $smtp = config('mail.mailers.'.$default, config('mail.mailers.smtp'));

        return [
            'default' => $default,
            'host' => (string) ($smtp['host'] ?? '—'),
            'port' => (string) ($smtp['port'] ?? '—'),
            'scheme' => (string) ($smtp['scheme'] ?? ($smtp['encryption'] ?? '—')),
            'username' => (string) ($smtp['username'] ?? '—'),
            'password' => ! empty($smtp['password']) ? 'set' : 'not set',
            'from_address' => (string) config('mail.from.address', '—'),
            'from_name' => (string) config('mail.from.name', '—'),
        ];
    }
}
