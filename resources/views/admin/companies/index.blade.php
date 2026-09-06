@extends('layouts.dashboard')

@section('title', 'Super-Admin — Companies')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Companies</h1>
            <p>Oversee every company on the platform and manage suspension.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>{{ number_format($companies->count()) }} {{ Str::plural('company', $companies->count()) }}</h2></div>
        @if ($companies->isEmpty())
            <div class="admin-empty">No companies yet.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr data-company-id="{{ $company->id }}">
                            <td>
                                <a class="cell-strong" href="{{ route('admin.clients.show', $company) }}">{{ $company->name }}</a>
                            </td>
                            <td class="mono">{{ $company->slug }}</td>
                            <td><span class="admin-pill admin-pill--{{ $company->status }}" data-status="{{ $company->status }}">{{ $company->status }}</span></td>
                            <td class="num">
                                @if ($company->isSuspended())
                                    <form method="POST" action="{{ route('admin.companies.unsuspend', $company) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm">Unsuspend</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.companies.suspend', $company) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm">Suspend</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
