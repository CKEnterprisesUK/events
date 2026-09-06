@extends('layouts.app')

@section('title', 'GDPR Data Tools')

@section('content')
    <section>
        <h1>GDPR Data Tools</h1>

        @if (session('status'))
            <p class="status" data-status="done">{{ session('status') }}</p>
        @endif

        <p class="muted">
            Export or delete the personal data your Company holds about a
            Customer, identified by their email address. Deleting anonymises the
            Customer's name and email while retaining the transactional records
            (order references, amounts, fees) required for reconciliation.
        </p>

        {{-- Export a Customer's stored personal data as JSON. (22.1) --}}
        <h2>Export Customer data</h2>
        <form method="POST" action="{{ route('dashboard.gdpr.export') }}">
            @csrf
            <div class="field">
                <label for="export_email">Customer email</label>
                <input type="email" name="customer_email" id="export_email" required>
                @error('customer_email')<p class="error">{{ $message }}</p>@enderror
            </div>
            <button type="submit">Export data</button>
        </form>

        {{-- Anonymise a Customer's personal data. (22.2) --}}
        <h2>Delete / anonymise Customer data</h2>
        <form method="POST" action="{{ route('dashboard.gdpr.anonymise') }}">
            @csrf
            <div class="field">
                <label for="anonymise_email">Customer email</label>
                <input type="email" name="customer_email" id="anonymise_email" required>
            </div>
            <button type="submit">Anonymise data</button>
        </form>
    </section>
@endsection
