@extends('layouts.app')

@section('title', 'Super-Admin — Transactions')

@section('content')
    <section>
        <h1>Platform Transactions</h1>

        <p class="muted">
            Every Company's transactions across the whole Platform. Amounts are
            shown in minor currency units.
        </p>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        <h2>Platform totals</h2>
        <table>
            <tbody>
                <tr>
                    <th scope="row">Companies</th>
                    <td data-metric="company_count">{{ $companyCount }}</td>
                </tr>
                <tr>
                    <th scope="row">Total platform fees earned</th>
                    <td data-metric="total_application_fees_minor">{{ $totalApplicationFeesMinor }}</td>
                </tr>
            </tbody>
        </table>

        <h2>All transactions</h2>
        @if (empty($transactions))
            <p>No transactions yet.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Order reference</th>
                        <th scope="col">Status</th>
                        <th scope="col">Total</th>
                        <th scope="col">Platform fee</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transactions as $txn)
                        <tr data-order-id="{{ $txn['id'] }}" data-company-id="{{ $txn['company_id'] }}">
                            <td>{{ $txn['company_name'] }}</td>
                            <td>{{ $txn['order_reference'] }}</td>
                            <td>{{ $txn['status'] }}</td>
                            <td data-metric="order_total_minor">{{ $txn['order_total_minor'] }}</td>
                            <td data-metric="application_fee_minor">{{ $txn['application_fee_minor'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
