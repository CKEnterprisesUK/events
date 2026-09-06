@extends('layouts.dashboard')

@section('title', 'Super-Admin — Transactions')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Platform transactions</h1>
            <p>Every company's transactions across the whole platform. All amounts are in GBP.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-stats">
        <div class="admin-stat">
            <span class="admin-stat__label">Companies</span>
            <span class="admin-stat__value" data-metric="company_count">{{ number_format($companyCount) }}</span>
            <span class="admin-stat__sub">On the platform</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Platform fees earned</span>
            <span class="admin-stat__value" data-metric="total_application_fees_minor" data-raw="{{ $totalApplicationFeesMinor }}">{{ $totalApplicationFeesGbp }}</span>
            <span class="admin-stat__sub">Realised across all companies</span>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>All transactions</h2></div>
        @if (empty($transactions))
            <div class="admin-empty">No transactions yet.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Order reference</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col" class="num">Platform fee</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $txn)
                        <tr data-order-id="{{ $txn['id'] }}" data-company-id="{{ $txn['company_id'] }}">
                            <td>{{ $txn['company_name'] }}</td>
                            <td class="mono">{{ $txn['order_reference'] }}</td>
                            <td><span class="admin-pill">{{ str_replace('_', ' ', $txn['status']) }}</span></td>
                            <td class="num" data-metric="order_total_minor" data-raw="{{ $txn['order_total_minor'] }}">{{ $txn['order_total_gbp'] }}</td>
                            <td class="num" data-metric="application_fee_minor" data-raw="{{ $txn['application_fee_minor'] }}">{{ $txn['application_fee_gbp'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
