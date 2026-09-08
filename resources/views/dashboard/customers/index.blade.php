@extends('layouts.dashboard')

@section('title', 'Customers')

@php use App\Http\Controllers\CustomerController; @endphp

@push('head')
<style>
    .filter-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1.25rem; }
    .filter-bar .field { margin: 0; }
    .filter-bar input { padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; min-width: 260px; }
    .filter-bar label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
    .notice { border-radius: 0.6rem; padding: 0.9rem 1.1rem; font-size: 0.9rem; line-height: 1.5; }
    .notice--warning { background: #fef6e7; border: 1px solid #f5d58a; color: #7a5200; }
    .notice--warning strong { display: block; margin-bottom: 0.25rem; }
    .page-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }

    /* Export confirmation modal */
    .export-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .export-modal[hidden] { display: none; }
    .export-modal__backdrop { position: absolute; inset: 0; background: rgba(16, 24, 40, 0.55); }
    .export-modal__panel {
        position: relative; background: var(--surface, #fff); border-radius: 0.75rem;
        max-width: 520px; width: 100%; padding: 1.5rem; box-shadow: 0 20px 45px rgba(16, 24, 40, 0.2);
    }
    .export-modal__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
    .export-modal__head h2 { margin: 0; font-size: 1.15rem; }
    .export-modal__close { background: none; border: 0; font-size: 1.5rem; line-height: 1; cursor: pointer; color: var(--muted); }
    .export-modal .field { margin: 1.1rem 0 0.35rem; }
    .export-modal label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
    .export-modal input[type="text"] {
        width: 100%; padding: 0.55rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem;
        text-transform: uppercase; letter-spacing: 0.06em;
    }
    .export-modal__hint { font-size: 0.82rem; color: var(--muted); margin: 0.15rem 0 0; }
    .export-modal__actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.25rem; }
    .btn[aria-disabled="true"] { opacity: 0.5; pointer-events: none; }
</style>
@endpush

@section('content')
    @php
        $currency = auth()->user()->company?->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
    @endphp

    @php $canExportAll = \Illuminate\Support\Facades\Gate::allows(\App\Services\RoleAuthorization::ACTION_MANAGE_GDPR); @endphp

    <div class="page-head">
        <h1>Customers</h1>
        @if ($canExportAll && ! $customers->isEmpty())
            <button type="button" class="btn btn-outline" data-open-export>
                Export all (CSV)
            </button>
        @endif
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <p class="muted">
        Everyone who has placed an order with you, identified by email. Open a
        customer to see their orders and, if you're the account owner, to export
        or erase their personal data for GDPR requests.
    </p>

    @if ($canExportAll && ! $customers->isEmpty())
        {{-- GDPR confirmation modal: the warning is shown here (on demand) rather
             than permanently on the page, and the export is locked behind typing
             "GDPR" so it can't be triggered by a stray click. --}}
        <div class="export-modal" data-export-modal hidden>
            <div class="export-modal__backdrop" data-close-export></div>
            <div class="export-modal__panel" role="dialog" aria-modal="true" aria-labelledby="export-modal-title">
                <div class="export-modal__head">
                    <h2 id="export-modal-title">Export all customers</h2>
                    <button type="button" class="export-modal__close" data-close-export aria-label="Close">&times;</button>
                </div>

                <div class="notice notice--warning" role="note" style="margin-top: 1rem;">
                    <strong>Your responsibilities under GDPR.</strong>
                    The exported file contains your customers' personal data (names
                    and email addresses). As the data controller you must keep it
                    secure, use it only for the purpose your customers were told
                    about, share it with no one who has no lawful reason to see it,
                    and delete it once it is no longer needed. Exporting does not
                    transfer these obligations to anyone else.
                </div>

                <div class="field">
                    <label for="export-confirm">Type <strong>GDPR</strong> to confirm</label>
                    <input type="text" id="export-confirm" data-export-confirm
                           autocomplete="off" autocapitalize="characters" spellcheck="false"
                           aria-describedby="export-confirm-hint">
                    <p class="export-modal__hint" id="export-confirm-hint">
                        This confirms you understand your data-controller responsibilities.
                    </p>
                </div>

                <div class="export-modal__actions">
                    <button type="button" class="btn btn-outline" data-close-export>Cancel</button>
                    <a class="btn" href="{{ route('dashboard.customers.export-all') }}" download
                       data-export-download aria-disabled="true" tabindex="-1">
                        Download CSV
                    </a>
                </div>
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('dashboard.customers.index') }}" class="filter-bar">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" name="q" id="q" value="{{ $search }}" placeholder="Name or email">
        </div>
        <button type="submit" class="btn">Search</button>
        @if ($search !== '')
            <a class="btn btn-outline" href="{{ route('dashboard.customers.index') }}">Clear</a>
        @endif
    </form>

    <div class="panel">
        @if ($customers->isEmpty())
            <div class="empty"><p>No customers yet.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col" class="num">Orders</th>
                        <th scope="col" class="num">Spend</th>
                        <th scope="col">Last order</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        @php $token = CustomerController::tokenFor($customer->customer_email); @endphp
                        <tr class="row-nav">
                            <td>
                                <a class="cell-strong row-link" href="{{ route('dashboard.customers.show', $token) }}">{{ $customer->customer_name }}</a>
                                <span class="cell-dim">{{ $customer->customer_email }}</span>
                            </td>
                            <td class="num">{{ number_format($customer->orders_count) }}</td>
                            <td class="num">{{ $symbol }}{{ number_format($customer->spend_minor / 100, 2) }}</td>
                            <td>{{ $customer->last_order_at ? \Illuminate\Support\Carbon::parse($customer->last_order_at)->format('j M Y, H:i') : '—' }}</td>
                            <td class="num"><a class="panel__link row-action" href="{{ route('dashboard.customers.show', $token) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $customers->links() }}</div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var modal = document.querySelector('[data-export-modal]');
        if (!modal) return;

        var confirmInput = modal.querySelector('[data-export-confirm]');
        var download = modal.querySelector('[data-export-download]');

        function open() {
            modal.hidden = false;
            reset();
            confirmInput.focus();
        }

        function close() {
            modal.hidden = true;
            reset();
        }

        // Lock the download until the confirmation matches "GDPR" (any case).
        function reset() {
            confirmInput.value = '';
            setEnabled(false);
        }

        function setEnabled(enabled) {
            download.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            download.setAttribute('tabindex', enabled ? '0' : '-1');
        }

        function isConfirmed() {
            return confirmInput.value.trim().toUpperCase() === 'GDPR';
        }

        document.querySelectorAll('[data-open-export]').forEach(function (el) {
            el.addEventListener('click', open);
        });
        modal.querySelectorAll('[data-close-export]').forEach(function (el) {
            el.addEventListener('click', close);
        });

        confirmInput.addEventListener('input', function () {
            setEnabled(isConfirmed());
        });

        // Block the download (and don't close the modal) unless confirmed.
        download.addEventListener('click', function (e) {
            if (!isConfirmed()) {
                e.preventDefault();
                return;
            }
            // Allowed: let the browser download, then tidy the modal away.
            window.setTimeout(close, 0);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) { close(); }
        });
    })();
</script>
@endpush
