@extends('layouts.dashboard')

@section('title', 'Super-Admin — Settings')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Platform settings</h1>
            <p>Platform-wide configuration and troubleshooting tools.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status status--error" role="alert">{{ session('error') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Mail configuration</h2></div>
        <table class="admin-facts">
            <tbody>
                <tr><th scope="row">Mailer</th><td class="mono">{{ $mail['default'] }}</td></tr>
                <tr><th scope="row">Host</th><td class="mono">{{ $mail['host'] }}</td></tr>
                <tr><th scope="row">Port</th><td class="mono">{{ $mail['port'] }}</td></tr>
                <tr><th scope="row">Scheme / encryption</th><td class="mono">{{ $mail['scheme'] }}</td></tr>
                <tr><th scope="row">Username</th><td class="mono">{{ $mail['username'] }}</td></tr>
                <tr>
                    <th scope="row">Password</th>
                    <td>
                        <span class="admin-pill {{ $mail['password'] === 'set' ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                            {{ $mail['password'] === 'set' ? 'Set' : 'Not set' }}
                        </span>
                    </td>
                </tr>
                <tr><th scope="row">From address</th><td class="mono">{{ $mail['from_address'] }}</td></tr>
                <tr><th scope="row">From name</th><td>{{ $mail['from_name'] }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Sending transport</h2></div>
        <div class="admin-panel__body">
            <p class="muted">
                Choose how the platform delivers all outgoing email — ticket
                emails, support notifications and the test email below. SMTP uses
                the mail server configured above. Microsoft Graph sends from your
                own domain mailbox via the Graph API.
            </p>

            <p>
                Currently sending via
                <span class="admin-pill admin-pill--active">{{ strtoupper($activeMailer) }}</span>
                @if ($selectedTransport === \App\Models\PlatformSetting::MAIL_TRANSPORT_GRAPH && ! $graphConfigured)
                    <span class="admin-pill admin-pill--suspended">Graph selected but not configured</span>
                @endif
            </p>

            @if (! $graphConfigured)
                <p class="muted">
                    Microsoft Graph is not configured yet. Add the
                    <code>GRAPH_MAIL_TENANT_ID</code>, <code>GRAPH_MAIL_CLIENT_ID</code>,
                    <code>GRAPH_MAIL_CLIENT_SECRET</code> and <code>GRAPH_MAIL_FROM</code>
                    values to the environment (the Azure AD app registration needs
                    the <code>Mail.Send</code> application permission with admin
                    consent). Until then, selecting Graph is safe — mail keeps
                    sending via SMTP.
                </p>
            @endif

            <form method="POST" action="{{ route('admin.settings.mail-transport') }}">
                @csrf
                <fieldset class="field">
                    <legend>Outbound mail transport</legend>

                    <label class="choice">
                        <input
                            type="radio"
                            name="mail_transport"
                            value="{{ \App\Models\PlatformSetting::MAIL_TRANSPORT_SMTP }}"
                            @checked($selectedTransport === \App\Models\PlatformSetting::MAIL_TRANSPORT_SMTP)
                        >
                        <span>SMTP (configured mail server)</span>
                    </label>

                    <label class="choice">
                        <input
                            type="radio"
                            name="mail_transport"
                            value="{{ \App\Models\PlatformSetting::MAIL_TRANSPORT_GRAPH }}"
                            @checked($selectedTransport === \App\Models\PlatformSetting::MAIL_TRANSPORT_GRAPH)
                        >
                        <span>Microsoft Graph (your domain mailbox){{ $graphConfigured ? '' : ' — not configured yet' }}</span>
                    </label>

                    @error('mail_transport')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </fieldset>
                <button type="submit" class="btn">Save transport</button>
            </form>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Microsoft Graph diagnostics</h2></div>
        <div class="admin-panel__body">
            <p class="muted">
                A read-only view of the configured Graph credentials (secrets
                masked) and a live authentication check. The check acquires an
                application token but sends no email, so it confirms the app
                registration and admin consent without touching a mailbox.
            </p>

            <table class="admin-facts">
                <tbody>
                    <tr>
                        <th scope="row">Configured</th>
                        <td>
                            <span class="admin-pill {{ $graph['configured'] === 'yes' ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                {{ $graph['configured'] === 'yes' ? 'Yes' : 'No' }}
                            </span>
                        </td>
                    </tr>
                    <tr><th scope="row">Tenant ID</th><td class="mono">{{ $graph['tenant_id'] }}</td></tr>
                    <tr><th scope="row">Client ID</th><td class="mono">{{ $graph['client_id'] }}</td></tr>
                    <tr>
                        <th scope="row">Client secret</th>
                        <td>
                            <span class="admin-pill {{ $graph['client_secret'] === 'set' ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                {{ $graph['client_secret'] === 'set' ? 'Set' : 'Not set' }}
                            </span>
                        </td>
                    </tr>
                    <tr><th scope="row">From mailbox</th><td class="mono">{{ $graph['from'] }}</td></tr>
                    <tr><th scope="row">Save to Sent Items</th><td>{{ $graph['save_to_sent_items'] === 'yes' ? 'Yes' : 'No' }}</td></tr>
                    <tr><th scope="row">Token endpoint</th><td class="mono">{{ $graph['token_url'] }}</td></tr>
                    <tr><th scope="row">sendMail endpoint</th><td class="mono">{{ $graph['send_mail_url'] }}</td></tr>
                </tbody>
            </table>

            <form method="POST" action="{{ route('admin.settings.graph-diagnostics') }}">
                @csrf
                <button type="submit" class="btn" {{ $graph['configured'] === 'yes' ? '' : 'disabled' }}>
                    Run Graph diagnostics
                </button>
                @if ($graph['configured'] !== 'yes')
                    <p class="muted">Add the Graph environment credentials to enable the live check.</p>
                @endif
            </form>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Send a test email</h2></div>
        <div class="admin-panel__body">
            <p class="muted">
                Sends a diagnostic message through the configured mailer. It is sent
                immediately, so any transport error is reported back here.
            </p>

            <form method="POST" action="{{ route('admin.settings.test-mail') }}">
                @csrf
                <div class="field">
                    <label for="test-email">Recipient email</label>
                    <input
                        type="email"
                        id="test-email"
                        name="email"
                        value="{{ old('email', $defaultTestEmail) }}"
                        required
                        placeholder="you@example.com"
                    >
                    @error('email')
                        <p class="error">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="btn">Send test email</button>
            </form>
        </div>
    </div>
@endsection
