@extends('layouts.dashboard')

@section('title', 'Super-Admin — ' . $company->name)

@php use App\Support\Money; @endphp

@section('content')
    <div class="admin-head">
        <div>
            <a class="admin-back" href="{{ route('admin.clients.index') }}">&#8592; Back to clients</a>
            <h1>{{ $company->name }}</h1>
            <p>
                <span class="admin-pill admin-pill--{{ $company->status }}">{{ $company->status }}</span>
                &nbsp;/{{ $company->slug }}
            </p>
        </div>
        <div class="admin-head__actions">
            @if (! $company->isSuspended())
                <form method="POST" action="{{ route('admin.impersonate.start', $company) }}">
                    @csrf
                    <button type="submit" class="btn">Impersonate</button>
                </form>
            @endif
            <a class="btn btn-outline" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener">View storefront</a>
            @if ($company->isSuspended())
                <form method="POST" action="{{ route('admin.companies.unsuspend', $company) }}">
                    @csrf
                    <button type="submit" class="btn">Unsuspend</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.companies.suspend', $company) }}">
                    @csrf
                    <button type="submit" class="btn btn-danger">Suspend</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @error('company')
        <p class="status status--error" role="alert">{{ $message }}</p>
    @enderror

    <div class="admin-stats">
        <div class="admin-stat">
            <span class="admin-stat__label">Events</span>
            <span class="admin-stat__value" data-metric="total_events">{{ number_format($stats['total_events']) }}</span>
            <span class="admin-stat__sub">{{ number_format($stats['published_events']) }} published</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Confirmed orders</span>
            <span class="admin-stat__value" data-metric="confirmed_orders">{{ number_format($stats['confirmed_orders']) }}</span>
            <span class="admin-stat__sub">{{ number_format($stats['tickets_sold']) }} tickets sold</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Gross sales</span>
            <span class="admin-stat__value" data-metric="gross_sales_minor">{{ Money::gbp($stats['gross_sales_minor']) }}</span>
            <span class="admin-stat__sub">Ticket subtotal</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Platform fees</span>
            <span class="admin-stat__value" data-metric="platform_fees_minor">{{ Money::gbp($stats['platform_fees_minor']) }}</span>
            <span class="admin-stat__sub">Earned from this client</span>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Profile</h2></div>
        <table class="admin-facts">
            <tbody>
                <tr><th scope="row">Legal name</th><td>{{ $company->legal_name ?? '—' }}</td></tr>
                <tr><th scope="row">Organisation type</th><td>{{ \App\Models\Company::ORGANISATION_TYPES[$company->organisation_type] ?? '—' }}</td></tr>
                <tr><th scope="row">Email</th><td>{{ $company->email ?? '—' }}</td></tr>
                <tr><th scope="row">Trading currency</th><td>{{ $company->currency }}</td></tr>
                <tr><th scope="row">Payments enabled</th><td>{{ $company->canAcceptPayments() ? 'Yes' : 'No' }}</td></tr>
                <tr><th scope="row">Team members</th><td>{{ number_format($stats['team_members']) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Financials</h2></div>
        <table class="admin-facts">
            <tbody>
                <tr><th scope="row">Gross sales</th><td data-metric="gross_sales_minor">{{ Money::gbp($stats['gross_sales_minor']) }}</td></tr>
                <tr><th scope="row">Booking fees</th><td data-metric="booking_fees_minor">{{ Money::gbp($stats['booking_fees_minor']) }}</td></tr>
                <tr><th scope="row">Platform fees earned</th><td data-metric="platform_fees_minor">{{ Money::gbp($stats['platform_fees_minor']) }}</td></tr>
                <tr><th scope="row">Order total (incl. fees)</th><td data-metric="order_total_minor">{{ Money::gbp($stats['order_total_minor']) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Payments (Stripe Connect)</h2></div>
        <table class="admin-facts">
            <tbody>
                <tr>
                    <th scope="row">Payment ready</th>
                    <td>
                        <span class="admin-pill {{ $company->canAcceptPayments() ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                            {{ $company->canAcceptPayments() ? 'Yes' : 'No' }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Connected account</th>
                    <td class="mono">{{ $company->stripe_account_id ?? '— (onboarding not started)' }}</td>
                </tr>
                <tr>
                    <th scope="row">Charges enabled</th>
                    <td>
                        <span class="admin-pill {{ $company->stripe_charges_enabled ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                            {{ $company->stripe_charges_enabled ? 'Yes' : 'No' }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
        @if ($company->stripe_account_id !== null && ! $company->canAcceptPayments())
            <p class="muted" style="padding: 0 1rem 1rem;">
                Onboarding has started but charges are not enabled. This company cannot sell paid tickets until Stripe finishes verifying the account.
            </p>
        @endif
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Owner &amp; access</h2></div>
        <div class="admin-panel__body">
            @if ($owner)
                <table class="admin-facts">
                    <tbody>
                        <tr><th scope="row">Owner</th><td>{{ $owner->name }}</td></tr>
                        <tr><th scope="row">Owner email</th><td class="mono">{{ $owner->email }}</td></tr>
                        <tr>
                            <th scope="row">Email verified</th>
                            <td>
                                <span class="admin-pill {{ $owner->hasVerifiedEmail() ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                    {{ $owner->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="admin-head__actions" style="margin-top: 1rem;">
                    <form method="POST" action="{{ route('admin.companies.owner.password-reset', $company) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">Email password reset</button>
                    </form>
                    @unless ($owner->hasVerifiedEmail())
                        <form method="POST" action="{{ route('admin.companies.owner.resend-verification', $company) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline">Resend verification</button>
                        </form>
                    @endunless
                </div>

                <p class="muted" style="margin-top: 0.75rem;">
                    Password reset recovers a locked-out or forgotten-password owner. Login lockouts are temporary (they clear within a minute on their own), so a reset link is the reliable way back in.
                </p>
            @else
                <p class="muted">This company has no owner on record.</p>
            @endif
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Transfer ownership</h2></div>
        <div class="admin-panel__body">
            @error('user_id')
                <p class="status status--error" role="alert">{{ $message }}</p>
            @enderror

            @if ($transferCandidates->isEmpty())
                <p class="muted">
                    There are no other users in this company to transfer ownership to. Invite another user first (from the company’s Team page), then transfer.
                </p>
            @else
                <p class="muted">
                    Move the owner role to another user in this company. The current owner is demoted to Admin. Use this when the owner has left the organisation.
                </p>
                <form method="POST" action="{{ route('admin.companies.owner.transfer', $company) }}">
                    @csrf
                    <div class="field">
                        <label for="transfer-user">New owner</label>
                        <select id="transfer-user" name="user_id" required>
                            <option value="">Select a user…</option>
                            @foreach ($transferCandidates as $candidate)
                                <option value="{{ $candidate->id }}">
                                    {{ $candidate->name }} ({{ $candidate->email }}) — {{ \App\Models\User::roleLabel($candidate->role) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Transfer ownership of {{ addslashes($company->name) }} to the selected user? The current owner will be demoted to Admin.');">
                        Transfer ownership
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Recent events</h2></div>
        @if ($recentEvents->isEmpty())
            <div class="admin-empty">No events yet.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">When</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Confirmed orders</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentEvents as $event)
                        <tr data-event-id="{{ $event->id }}">
                            <td><span class="cell-strong">{{ $event->name }}</span></td>
                            <td>{{ $event->starts_at ? $event->starts_at->format('j M Y, H:i') : '—' }}</td>
                            <td><span class="pill {{ $event->is_published ? 'pill--live' : 'pill--draft' }}">{{ $event->is_published ? 'Published' : 'Draft' }}</span></td>
                            <td class="num">{{ number_format($event->confirmed_orders_count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Recent support requests</h2></div>
        @if ($recentSupportRequests->isEmpty())
            <div class="admin-empty">No support requests from this client.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Status</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Raised by</th>
                        <th scope="col">Raised</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentSupportRequests as $ticket)
                        <tr data-ticket-id="{{ $ticket->id }}">
                            <td>
                                <span class="pill {{ $ticket->isClosed() ? 'pill--live' : 'pill--draft' }}">{{ $ticket->statusLabel() }}</span>
                            </td>
                            <td><a href="{{ route('admin.support.show', $ticket->id) }}" class="cell-strong">{{ $ticket->subject }}</a></td>
                            <td>{{ $ticket->user?->name ?? 'Unknown' }}</td>
                            <td>{{ optional($ticket->created_at)->format('j M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
