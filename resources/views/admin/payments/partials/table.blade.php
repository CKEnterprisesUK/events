<table class="admin-table">
    <thead>
        <tr>
            <th scope="col">Company</th>
            <th scope="col">Company status</th>
            <th scope="col">Connected account</th>
            <th scope="col">Charges enabled</th>
            <th scope="col"></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($companies as $company)
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
                <td class="num"><a class="panel__link" href="{{ route('admin.clients.show', $company) }}">View</a></td>
            </tr>
        @endforeach
    </tbody>
</table>
