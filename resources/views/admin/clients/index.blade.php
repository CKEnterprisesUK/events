@extends('layouts.dashboard')

@section('title', 'Super-Admin — Clients')

@php use App\Support\Money; @endphp

@section('content')
    <div class="admin-head">
        <div>
            <h1>Clients</h1>
            <p>Every company on the platform with its headline stats. All amounts are in GBP.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head">
            <h2>{{ number_format($companies->count()) }} {{ Str::plural('company', $companies->count()) }}</h2>
        </div>
        @if ($companies->isEmpty())
            <div class="admin-empty">No companies yet.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Events</th>
                        <th scope="col" class="num">Orders</th>
                        <th scope="col" class="num">Gross sales</th>
                        <th scope="col" class="num">Platform fees</th>
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
                            <td><span class="admin-pill admin-pill--{{ $company->status }}" data-status="{{ $company->status }}">{{ $company->status }}</span></td>
                            <td class="num">{{ number_format($company->events_count) }}</td>
                            <td class="num">{{ number_format($company->confirmed_orders_count) }}</td>
                            <td class="num">{{ Money::gbp($company->gross_sales_minor) }}</td>
                            <td class="num">{{ Money::gbp($company->platform_fees_minor) }}</td>
                            <td class="num"><a class="panel__link" href="{{ route('admin.clients.show', $company) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
