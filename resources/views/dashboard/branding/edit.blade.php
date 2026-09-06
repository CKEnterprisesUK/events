@extends('layouts.app')

@section('title', 'Branding')

@section('content')
    <section>
        <h1>Branding</h1>

        @if (session('status'))
            <p class="status" data-status="saved">{{ session('status') }}</p>
        @endif

        {{-- Effective Company branding preview: logo (7.1), primary colour (7.2). --}}
        <div class="branding-preview" @if ($branding->hasPrimaryColour()) style="--brand: {{ $branding->primaryColour }}" @endif>
            @if ($branding->hasLogo())
                <img class="brand-logo" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}" alt="Company logo">
            @else
                <p>No logo uploaded.</p>
            @endif

            @if ($branding->hasPrimaryColour())
                <p data-primary-colour="{{ $branding->primaryColour }}">Primary colour: {{ $branding->primaryColour }}</p>
            @endif
        </div>

        <form method="POST" action="{{ route('dashboard.branding.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            {{-- Logo upload — stored and displayed on Storefront/tickets. (7.1) --}}
            <div class="field">
                <label for="logo">Logo</label>
                <input type="file" name="logo" id="logo" accept="image/*">
                @error('logo')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Primary brand colour applied to the Storefront. (7.2) --}}
            <div class="field">
                <label for="primary_colour">Primary colour</label>
                <input type="text" name="primary_colour" id="primary_colour"
                       value="{{ old('primary_colour', $company->primary_colour) }}" placeholder="#2563eb">
                @error('primary_colour')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Terms & Conditions shown at checkout. (7.3) --}}
            <div class="field">
                <label for="terms_text">Terms &amp; Conditions</label>
                <textarea name="terms_text" id="terms_text" rows="6">{{ old('terms_text', $company->terms_text) }}</textarea>
                @error('terms_text')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Custom ticket information fields printed on tickets. (7.4) --}}
            <fieldset class="field">
                <legend>Custom ticket fields</legend>
                @php($fields = $branding->ticketFieldDefs)
                @for ($i = 0; $i < 5; $i++)
                    <input type="text" name="ticket_field_defs[]"
                           value="{{ $fields[$i]['label'] ?? '' }}" placeholder="Field label">
                @endfor
                @error('ticket_field_defs.*')<p class="error">{{ $message }}</p>@enderror
            </fieldset>

            <button type="submit" class="btn">Save branding</button>
        </form>
    </section>
@endsection
