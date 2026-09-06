@extends('layouts.dashboard')

@section('title', 'Super-Admin — Fees')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Fee configuration</h1>
            <p>Set the platform-wide fee and per-company overrides. Changes only affect future orders.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Global fee percent</h2></div>
        <div class="admin-panel__body">
            <p class="muted">Applied to any company without a per-company override.</p>
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
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Per-company fees</h2></div>
        <div class="admin-panel__body">
            @if ($companies->isEmpty())
                <p class="muted">No companies yet.</p>
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
                            <button type="submit" class="btn btn-sm">Save</button>
                        </fieldset>
                    </form>
                @endforeach
            @endif
        </div>
    </div>
@endsection
