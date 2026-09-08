<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\AuditLogger;
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
    ) {}

    /**
     * Show the effective mail configuration (read-only diagnostics), the
     * outbound-transport toggle, and the test-email form.
     */
    public function index(Request $request): View
    {
        return view('admin.settings.index', [
            'mail' => $this->mailDiagnostics(),
            'defaultTestEmail' => $request->user()?->email,
            'selectedTransport' => $this->transport->selectedTransport(),
            'activeMailer' => $this->transport->activeMailer(),
            'graphConfigured' => $this->transport->graphConfigured(),
        ]);
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
                ->with('error', __('Test email failed: :message', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('status', __('Test email sent to :email via the ":mailer" mailer.', [
                'email' => $validated['email'],
                'mailer' => $mailerName,
            ]));
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
