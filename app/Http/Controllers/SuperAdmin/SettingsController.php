<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Super-admin platform settings surface. Today it hosts mail troubleshooting:
 * a read-only view of the effective mail configuration plus a tool to send a
 * diagnostic test email to any address, so a Super_Admin can confirm the
 * configured transport actually delivers.
 *
 * This surface is not tenant-scoped — a Super_Admin operates across the whole
 * Platform. Nothing here is persisted; mail configuration lives in the
 * environment / config, and the test send is dispatched synchronously so the
 * admin sees an immediate success or the failure reason.
 */
class SettingsController extends Controller
{
    /**
     * Show the effective mail configuration (read-only diagnostics) and the
     * test-email form.
     */
    public function index(Request $request): View
    {
        return view('admin.settings.index', [
            'mail' => $this->mailDiagnostics(),
            'defaultTestEmail' => $request->user()?->email,
        ]);
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
