@extends('layouts.dashboard')

@section('title', 'Super-Admin — ' . $company->name)

@php use App\Support\Money; @endphp

@push('head')
<style>
    .user-actions summary { display: inline-flex; cursor: pointer; list-style: none; }
    .user-actions summary::-webkit-details-marker { display: none; }
    .user-actions__panel {
        margin-top: 0.75rem; padding: 0.85rem; border: 1px solid var(--border);
        border-radius: 0.5rem; background: #fafafa; max-width: 32rem;
    }
    .user-actions__panel .field { display: flex; flex-direction: column; gap: 0.25rem; margin-bottom: 0.6rem; }
    .user-actions__panel .field label { font-size: 0.75rem; font-weight: 600; color: #6b7280; }
    .user-actions__panel .field input, .user-actions__panel .field select {
        padding: 0.4rem 0.55rem; border: 1px solid var(--border); border-radius: 0.4rem; font-size: 0.9rem;
    }
    .user-actions__row { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.85rem; padding-top: 0.75rem; border-top: 1px solid var(--border); }
    .user-actions__row form { margin: 0; }
    .btn-sm { padding: 0.3rem 0.7rem; font-size: 0.82rem; }
</style>
@endpush

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
                @if ($company->stripe_account_id !== null)
                    <tr>
                        <th scope="row">Payouts enabled</th>
                        <td>
                            <span class="admin-pill {{ $company->stripe_payouts_enabled ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                {{ $company->stripe_payouts_enabled ? 'Yes' : 'No' }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Details submitted</th>
                        <td>
                            <span class="admin-pill {{ $company->stripe_details_submitted ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                {{ $company->stripe_details_submitted ? 'Yes' : 'No' }}
                            </span>
                        </td>
                    </tr>
                    @if ($company->stripe_disabled_reason)
                        <tr>
                            <th scope="row">Restriction reason</th>
                            <td class="mono">{{ $company->stripe_disabled_reason }}</td>
                        </tr>
                    @endif
                @endif
            </tbody>
        </table>
        @php
            $requirementLabels = [
                'company.verification.document' => 'Business verification document',
                'individual.verification.document' => 'Identity verification document',
                'individual.verification.additional_document' => 'Additional identity document',
                'company.tax_id' => 'Company tax ID / registration number',
                'business_profile.url' => 'Business website',
                'business_profile.mcc' => 'Business category',
                'external_account' => 'Bank account for payouts',
                'tos_acceptance.date' => 'Accept Stripe terms of service',
            ];
            $labelFor = static fn (string $id): string => $requirementLabels[$id]
                ?? ucfirst(str_replace(['_', '.'], [' ', ' — '], $id));

            $reqs = $company->stripe_requirements ?? [];
            $needsAction = array_values(array_unique(array_merge(
                (array) ($reqs['past_due'] ?? []),
                (array) ($reqs['currently_due'] ?? []),
            )));
            $pending = (array) ($reqs['pending_verification'] ?? []);
            $reqErrors = (array) ($reqs['errors'] ?? []);
        @endphp
        @if ($company->stripe_account_id !== null && ! $company->canAcceptPayments())
            <div class="muted" style="padding: 0 1rem 1rem;" data-stripe="requirements">
                @if (! empty($needsAction))
                    <p><strong>Waiting on the client</strong> — they need to provide the following by logging in to their own Stripe account (dashboard.stripe.com) or via the "Finish Stripe setup" button on their Payments page:</p>
                    <ul data-req="action-required">
                        @foreach ($needsAction as $req)
                            <li>{{ $labelFor($req) }}</li>
                        @endforeach
                    </ul>
                @elseif (! empty($pending))
                    <p data-req="pending-verification"><strong>Under review by Stripe</strong> — {{ collect($pending)->map($labelFor)->implode(', ') }}. Charges enable automatically once Stripe finishes reviewing. Nothing is needed from the client right now.</p>
                @else
                    <p>Onboarding has started but charges are not enabled. This company cannot sell paid tickets until Stripe finishes verifying the account.</p>
                @endif

                @if (! empty($reqErrors))
                    <p><strong>Stripe's notes:</strong></p>
                    <ul data-req="errors">
                        @foreach ($reqErrors as $error)
                            @if (! empty($error['reason']))
                                <li>{{ $error['reason'] }}</li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>
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
        <div class="admin-panel__head"><h2>Team members ({{ number_format($companyUsers->count()) }})</h2></div>
        <div class="admin-panel__body">
            @if ($errors->has('name') || $errors->has('email') || $errors->has('role'))
                <p class="status status--error" role="alert">
                    {{ $errors->first('name') ?: ($errors->first('email') ?: $errors->first('role')) }}
                </p>
            @endif

            @if ($companyUsers->isEmpty())
                <p class="muted">This company has no users.</p>
            @else
                <p class="muted" style="margin-bottom: 1rem;">
                    Edit a user, reset their password, resend verification, or disable/delete the account. The owner’s role is changed via <em>Transfer ownership</em> above — it can’t be edited here.
                </p>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($companyUsers as $member)
                            <tr id="user-{{ $member->id }}" data-user-id="{{ $member->id }}">
                                <td class="cell-strong">{{ $member->name }}</td>
                                <td class="mono">
                                    {{ $member->email }}
                                    @unless ($member->hasVerifiedEmail())
                                        <span class="cell-dim">(unverified)</span>
                                    @endunless
                                </td>
                                <td>{{ \App\Models\User::roleLabel($member->role) }}{{ $member->isOwner() ? ' (owner)' : '' }}</td>
                                <td>
                                    <span class="admin-pill {{ $member->isDisabled() ? 'admin-pill--suspended' : 'admin-pill--active' }}">
                                        {{ $member->isDisabled() ? 'Disabled' : 'Active' }}
                                    </span>
                                </td>
                                <td>
                                    <details class="user-actions">
                                        <summary class="btn btn-outline btn-sm">Manage</summary>
                                        <div class="user-actions__panel">
                                            {{-- Edit name / email / role --}}
                                            <form method="POST" action="{{ route('admin.users.update', $member->id) }}" class="user-edit-form">
                                                @csrf
                                                @method('PUT')
                                                <div class="field">
                                                    <label for="name-{{ $member->id }}">Name</label>
                                                    <input type="text" id="name-{{ $member->id }}" name="name" value="{{ old('name', $member->name) }}" required>
                                                </div>
                                                <div class="field">
                                                    <label for="email-{{ $member->id }}">Email</label>
                                                    <input type="email" id="email-{{ $member->id }}" name="email" value="{{ old('email', $member->email) }}" required>
                                                </div>
                                                @unless ($member->isOwner())
                                                    <div class="field">
                                                        <label for="role-{{ $member->id }}">Role</label>
                                                        <select id="role-{{ $member->id }}" name="role" required>
                                                            @foreach (\App\Models\User::INVITABLE_ROLES as $r)
                                                                <option value="{{ $r }}" @selected(old('role', $member->role) === $r)>{{ \App\Models\User::roleLabel($r) }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                @endunless
                                                <button type="submit" class="btn btn-sm">Save changes</button>
                                            </form>

                                            {{-- Recovery + lifecycle actions --}}
                                            <div class="user-actions__row">
                                                <form method="POST" action="{{ route('admin.users.password-reset', $member->id) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm">Email password reset</button>
                                                </form>
                                                @unless ($member->hasVerifiedEmail())
                                                    <form method="POST" action="{{ route('admin.users.resend-verification', $member->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline btn-sm">Resend verification</button>
                                                    </form>
                                                @endunless
                                                @if ($member->isDisabled())
                                                    <form method="POST" action="{{ route('admin.users.enable', $member->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline btn-sm">Enable account</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('admin.users.disable', $member->id) }}">
                                                        @csrf
                                                        <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Disable {{ addslashes($member->email) }}? They will be signed out and unable to log in until re-enabled.');">Disable account</button>
                                                    </form>
                                                @endif
                                                <form method="POST" action="{{ route('admin.users.destroy', $member->id) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Permanently delete {{ addslashes($member->email) }}? This cannot be undone.');">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
