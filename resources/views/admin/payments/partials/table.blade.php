<table class="admin-table">
    <thead>
        <tr>
            <th scope="col">Company</th>
            <th scope="col">Company status</th>
            <th scope="col">Connected account</th>
            <th scope="col">Charges enabled</th>
            <th scope="col">Outstanding with Stripe</th>
            <th scope="col"></th>
        </tr>
    </thead>
    <tbody>
        @php
            // Friendly labels for the Stripe requirement ids, mirroring the
            // Owner-facing Payments page so Super_Admin and Owner see the same
            // language when discussing "why isn't this account enabled".
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
            $labelFor = static function (string $id) use ($requirementLabels): string {
                return $requirementLabels[$id] ?? ucfirst(str_replace(['_', '.'], [' ', ' — '], $id));
            };
        @endphp
        @foreach ($companies as $company)
            @php
                $reqs = $company->stripe_requirements ?? [];
                $needsAction = array_values(array_unique(array_merge(
                    (array) ($reqs['past_due'] ?? []),
                    (array) ($reqs['currently_due'] ?? []),
                )));
                $pending = (array) ($reqs['pending_verification'] ?? []);
            @endphp
            <tr data-company-id="{{ $company->id }}">
                <td>
                    <a class="cell-strong" href="{{ route('admin.clients.show', $company) }}">{{ $company->name }}</a>
                    <span class="cell-dim">/{{ $company->slug }}</span>
                </td>
                <td>
                    <span class="admin-pill admin-pill--{{ $company->status }}">{{ $company->status }}</span>
                </td>
                <td class="mono">{{ $company->stripe_account_id ?? '—' }}</td>
                <td>
                    <span class="admin-pill {{ $company->stripe_charges_enabled ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                        {{ $company->stripe_charges_enabled ? 'Yes' : 'No' }}
                    </span>
                </td>
                <td class="req-cell">
                    @if ($company->stripe_charges_enabled)
                        <span class="cell-dim">—</span>
                    @elseif ($company->stripe_account_id === null)
                        <span class="cell-dim">Onboarding not started</span>
                    @else
                        @if (! empty($needsAction))
                            <span class="cell-dim">Action needed from client:</span>
                            <ul class="req-cell__list" data-req="action-required">
                                @foreach ($needsAction as $req)
                                    <li>{{ $labelFor($req) }}</li>
                                @endforeach
                            </ul>
                        @elseif (! empty($pending))
                            <span class="req-cell__pending" data-req="pending-verification">Under review by Stripe: {{ collect($pending)->map($labelFor)->implode(', ') }}</span>
                        @elseif ($company->stripe_disabled_reason)
                            <span class="cell-dim">{{ ucfirst(str_replace(['_', '.'], [' ', ' — '], $company->stripe_disabled_reason)) }}</span>
                        @else
                            <span class="cell-dim">Onboarding not finished</span>
                        @endif
                    @endif
                </td>
                <td class="num"><a class="panel__link" href="{{ route('admin.clients.show', $company) }}">View</a></td>
            </tr>
        @endforeach
    </tbody>
</table>
