@extends('layouts.dashboard')

@section('title', 'Super-Admin — Settings')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Platform settings</h1>
            <p>Fees, email, Stripe and other platform-wide configuration.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status status--error" role="alert">{{ session('error') }}</p>
    @endif

    <div class="tabs" id="settings-tabs" data-initial-tab="{{ $initialTab }}">
        <div class="tab-list" role="tablist" aria-label="Settings sections">
            <button type="button" class="tab-btn" role="tab" id="tab-fees" aria-controls="panel-fees" data-tab="fees">Fees</button>
            <button type="button" class="tab-btn" role="tab" id="tab-email" aria-controls="panel-email" data-tab="email">Email</button>
            <button type="button" class="tab-btn" role="tab" id="tab-stripe" aria-controls="panel-stripe" data-tab="stripe">Stripe</button>
            <button type="button" class="tab-btn" role="tab" id="tab-reserved-slugs" aria-controls="panel-reserved-slugs" data-tab="reserved-slugs">Reserved slugs</button>
        </div>

        <div class="tab-panel" role="tabpanel" id="panel-fees" aria-labelledby="tab-fees" data-tab-panel="fees" hidden>
            <div class="settings-split">
                <div class="admin-panel">
                    <div class="admin-panel__head"><h2>Global fee percent</h2></div>
                    <div class="admin-panel__body">
                        <p class="muted">Applied to any company without its own override.</p>
                        <form method="POST" action="{{ route('admin.fees.global.update') }}">
                            @csrf
                            @method('PUT')
                            <div class="field">
                                <label for="global_fee_percent">Global fee percent</label>
                                <input type="number" step="0.01" min="0" max="100" id="global_fee_percent" name="global_fee_percent"
                                       value="{{ old('global_fee_percent', $globalFeePercent) }}">
                                @error('global_fee_percent')<p class="error">{{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="btn">Save global fee</button>
                        </form>
                    </div>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel__head"><h2>Stripe fee estimate</h2></div>
                    <div class="admin-panel__body">
                        <p class="muted">
                            Display guidance only, shown on the organiser pricing calculator and Stripe status
                            page. The exact fee on each order is read from Stripe. Update it if Stripe changes its rates.
                        </p>
                        <form method="POST" action="{{ route('admin.fees.stripe-estimate.update') }}">
                            @csrf
                            @method('PUT')
                            <div class="settings-split settings-split--tight">
                                <div class="field">
                                    <label for="stripe_fee_percent">Percent</label>
                                    <input type="number" step="0.01" min="0" max="100" id="stripe_fee_percent" name="stripe_fee_percent"
                                           value="{{ old('stripe_fee_percent', $stripeFeePercent) }}">
                                    @error('stripe_fee_percent')<p class="error">{{ $message }}</p>@enderror
                                </div>
                                <div class="field">
                                    <label for="stripe_fee_fixed">Fixed (per transaction)</label>
                                    <input type="number" step="0.01" min="0" max="10000" id="stripe_fee_fixed" name="stripe_fee_fixed"
                                           value="{{ old('stripe_fee_fixed', number_format($stripeFeeFixedMinor / 100, 2, '.', '')) }}">
                                    @error('stripe_fee_fixed')<p class="error">{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <button type="submit" class="btn">Save Stripe estimate</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head">
                    <h2>Per-company fees</h2>
                    <span class="muted">Leave the percent blank to use the global fee ({{ rtrim(rtrim((string) $globalFeePercent, '0'), '.') }}%). Changes only affect future orders.</span>
                </div>
                @if ($errors->has('company_fee_percent') || $errors->has('fee_handling_mode'))
                    <p class="status status--error" role="alert" style="margin:1rem 1.25rem 0;">
                        {{ $errors->first('company_fee_percent') ?: $errors->first('fee_handling_mode') }}
                    </p>
                @endif
                @if ($companies->isEmpty())
                    <div class="admin-empty">No companies yet.</div>
                @else
                    <div class="table-scroll">
                        <table class="admin-table fee-table">
                            <thead>
                                <tr>
                                    <th scope="col">Company</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Fee %</th>
                                    <th scope="col">Handling</th>
                                    <th scope="col" class="num">Effective</th>
                                    <th scope="col"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($companies as $company)
                                    @php($formId = 'fee-form-'.$company->id)
                                    <tr data-company-id="{{ $company->id }}">
                                        <td>
                                            {{-- Row form; the inputs in the other cells attach via the form attribute. --}}
                                            <form id="{{ $formId }}" method="POST" action="{{ route('admin.fees.company.update', $company) }}">
                                                @csrf
                                                @method('PUT')
                                            </form>
                                            <span class="cell-strong">{{ $company->name }}</span>
                                            <span class="cell-dim">/{{ $company->slug }}</span>
                                        </td>
                                        <td><span class="admin-pill admin-pill--{{ $company->status }}">{{ $company->status }}</span></td>
                                        <td>
                                            <input type="number" step="0.01" min="0" max="100" form="{{ $formId }}"
                                                   name="company_fee_percent" id="company_fee_percent_{{ $company->id }}"
                                                   value="{{ $company->company_fee_percent }}" placeholder="{{ $globalFeePercent }}"
                                                   aria-label="Fee percent for {{ $company->name }} (blank uses global)" class="fee-input">
                                        </td>
                                        <td>
                                            <select form="{{ $formId }}" name="fee_handling_mode" id="fee_handling_mode_{{ $company->id }}"
                                                    aria-label="Fee handling mode for {{ $company->name }}">
                                                @foreach (\App\Models\Company::FEE_MODES as $mode)
                                                    <option value="{{ $mode }}" @selected($company->fee_handling_mode === $mode)>{{ $mode }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="num">
                                            {{ rtrim(rtrim((string) ($company->company_fee_percent ?? $globalFeePercent), '0'), '.') }}%
                                            @if ($company->company_fee_percent === null)
                                                <span class="cell-dim">global</span>
                                            @else
                                                <span class="cell-dim">override</span>
                                            @endif
                                        </td>
                                        <td class="num"><button type="submit" form="{{ $formId }}" class="btn btn-sm">Save</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="tab-panel" role="tabpanel" id="panel-email" aria-labelledby="tab-email" data-tab-panel="email" hidden>
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
        </div>

        <div class="tab-panel" role="tabpanel" id="panel-stripe" aria-labelledby="tab-stripe" data-tab-panel="stripe" hidden>
        <div class="admin-panel">
            <div class="admin-panel__head"><h2>Stripe credentials</h2></div>
            <div class="admin-panel__body">
                <p class="muted">
                    A read-only view of the configured Stripe API credentials (secret
                    masked) and a live authentication check. The check retrieves the
                    platform account but makes no charge, so it confirms the secret
                    key is valid before a customer ever reaches checkout.
                </p>

                <table class="admin-facts">
                    <tbody>
                        <tr>
                            <th scope="row">Secret key</th>
                            <td>
                                <span class="admin-pill {{ $stripe['secret'] === 'set' ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                    {{ $stripe['secret'] === 'set' ? 'Set' : 'Not set' }}
                                </span>
                            </td>
                        </tr>
                        <tr><th scope="row">Secret fingerprint</th><td class="mono">{{ $stripe['secret_fingerprint'] }}</td></tr>
                        <tr>
                            <th scope="row">Mode</th>
                            <td>
                                <span class="admin-pill {{ $stripe['mode'] === 'live' ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                    {{ strtoupper($stripe['mode']) }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Webhook signing secret</th>
                            <td>
                                <span class="admin-pill {{ $stripe['webhook_secret'] === 'set' ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                    {{ $stripe['webhook_secret'] === 'set' ? 'Set' : 'Not set' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form method="POST" action="{{ route('admin.settings.stripe-diagnostics') }}">
                    @csrf
                    <button type="submit" class="btn" {{ $stripeConfigured ? '' : 'disabled' }}>
                        Run Stripe diagnostics
                    </button>
                    @if (! $stripeConfigured)
                        <p class="muted">Add <code>STRIPE_SECRET</code> to the environment to enable the live check.</p>
                    @elseif ($stripe['webhook_secret'] !== 'set')
                        <p class="muted">
                            <code>STRIPE_WEBHOOK_SECRET</code> is not set — incoming Stripe
                            webhooks (payment confirmations, refunds) will be rejected until
                            it is configured.
                        </p>
                    @endif
                </form>
            </div>
        </div>
        </div>

        <div class="tab-panel" role="tabpanel" id="panel-reserved-slugs" aria-labelledby="tab-reserved-slugs" data-tab-panel="reserved-slugs" hidden>
            <p class="muted" style="margin-top:0;">
                Slugs a company can never claim at sign-up or when changing its storefront address. This blocks
                reserved platform routes (like <span class="mono">admin</span> or <span class="mono">login</span>),
                infrastructure paths, and any brand or abuse words you want to keep off the platform.
            </p>

            <div class="admin-panel">
                <div class="admin-panel__head"><h2>Add a reserved slug</h2></div>
                <div class="admin-panel__body">
                    <form method="POST" action="{{ route('admin.reserved-slugs.store') }}">
                        @csrf
                        <div class="field">
                            <label for="slug">Slug</label>
                            <p class="hint">Lowercase letters, numbers and hyphens only, the same shape a storefront address takes.</p>
                            <input type="text" id="slug" name="slug" value="{{ old('slug') }}" required maxlength="255" pattern="[a-z0-9-]+">
                            @error('slug')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="reason">Reason <span class="hint">(optional)</span></label>
                            <input type="text" id="reason" name="reason" value="{{ old('reason') }}" maxlength="255">
                            @error('reason')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn">Reserve slug</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="admin-panel">
                <div class="admin-panel__head"><h2>Reserved slugs</h2></div>
                <div class="table-scroll">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Slug</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Type</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($slugs as $slug)
                                <tr>
                                    <td class="mono">{{ $slug->slug }}</td>
                                    <td>{{ $slug->reason ?? '—' }}</td>
                                    <td>
                                        @if ($slug->is_system)
                                            <span class="admin-pill">System</span>
                                        @else
                                            <span class="admin-pill admin-pill--active">Custom</span>
                                        @endif
                                    </td>
                                    <td class="num">
                                        @if ($slug->is_system)
                                            <span class="hint">Protected</span>
                                        @else
                                            <form method="POST" action="{{ route('admin.reserved-slugs.destroy', $slug) }}" onsubmit="return confirm('Remove &quot;{{ $slug->slug }}&quot; from the blocklist? A Company could then claim it.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('head')
    <style>
        .settings-split { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 0 1.5rem; align-items: start; }
        .settings-split--tight { gap: 0 1rem; }
        .fee-table .fee-input { width: 6.5rem; }
        .fee-table td { white-space: nowrap; }
        .fee-table .cell-dim { display: inline; margin-left: .35rem; }
        .table-scroll { overflow-x: auto; }
        .admin-panel__head .muted { font-size: .85rem; }
    </style>
@endpush

@push('scripts')
    <script>
        (function () {
            var root = document.getElementById('settings-tabs');
            if (!root) return;
            var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
            var panels = Array.prototype.slice.call(root.querySelectorAll('[data-tab-panel]'));
            var KEY = 'ck.admin.settings.tab';
            var names = buttons.map(function (b) { return b.getAttribute('data-tab'); });

            function activate(name, focus) {
                buttons.forEach(function (b) {
                    var on = b.getAttribute('data-tab') === name;
                    b.setAttribute('aria-selected', String(on));
                    b.tabIndex = on ? 0 : -1;
                    if (on && focus) b.focus();
                });
                panels.forEach(function (p) { p.hidden = p.getAttribute('data-tab-panel') !== name; });
                try { sessionStorage.setItem(KEY, name); } catch (e) {}
            }

            buttons.forEach(function (b, i) {
                b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); });
                b.addEventListener('keydown', function (e) {
                    var next = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
                    if (next === null) return;
                    e.preventDefault();
                    activate(names[(next + names.length) % names.length], true);
                });
            });

            // Priority: URL hash, server-chosen tab, a tab with a validation
            // error, last-used tab, then the first tab.
            var hash = location.hash.replace('#', '');
            var stored = null;
            try { stored = sessionStorage.getItem(KEY); } catch (e) {}
            var errPanel = panels.filter(function (p) { return p.querySelector('.error'); })[0];
            var candidates = [hash, root.getAttribute('data-initial-tab'), errPanel && errPanel.getAttribute('data-tab-panel'), stored, names[0]];
            var start = candidates.filter(function (c) { return c && names.indexOf(c) !== -1; })[0];
            activate(start);
        })();
    </script>
@endpush
