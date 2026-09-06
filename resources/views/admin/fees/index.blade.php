@extends('layouts.app')

@section('title', 'Super-Admin — Fees')

@section('content')
    <section>
        <h1>Fee Configuration</h1>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        <h2>Global fee percent</h2>
        <p class="muted">
            Applied to any Company without a per-Company override.
        </p>
        <form method="POST" action="{{ route('admin.fees.global.update') }}">
            @csrf
            @method('PUT')
            <div class="field">
                <label for="global_fee_percent">Global fee percent</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    max="100"
                    id="global_fee_percent"
                    name="global_fee_percent"
                    value="{{ old('global_fee_percent', $globalFeePercent) }}"
                >
                @error('global_fee_percent')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="btn">Save global fee</button>
        </form>

        <h2>Per-company fees</h2>
        @if ($companies->isEmpty())
            <p>No companies yet.</p>
        @else
            @foreach ($companies as $company)
                <form method="POST" action="{{ route('admin.fees.company.update', $company) }}" data-company-id="{{ $company->id }}">
                    @csrf
                    @method('PUT')
                    <fieldset>
                        <legend>{{ $company->name }}</legend>
                        <div class="field">
                            <label for="company_fee_percent_{{ $company->id }}">Company fee percent (blank = use global)</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                id="company_fee_percent_{{ $company->id }}"
                                name="company_fee_percent"
                                value="{{ $company->company_fee_percent }}"
                            >
                        </div>
                        <div class="field">
                            <label for="fee_handling_mode_{{ $company->id }}">Fee handling mode</label>
                            <select id="fee_handling_mode_{{ $company->id }}" name="fee_handling_mode">
                                @foreach (\App\Models\Company::FEE_MODES as $mode)
                                    <option value="{{ $mode }}" @selected($company->fee_handling_mode === $mode)>{{ $mode }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn">Save</button>
                    </fieldset>
                </form>
            @endforeach
        @endif
    </section>
@endsection
