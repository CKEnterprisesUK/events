@extends('layouts.dashboard')

@section('title', 'Event branding')

@section('content')
    <section>
        <h1>Branding &mdash; {{ $event->name }}</h1>

        @if (session('status'))
            <p class="status" data-status="saved">{{ session('status') }}</p>
        @endif

        <p>Leave a field blank to inherit the Company-level branding. (Requirement 7.5)</p>

        {{-- Effective (resolved) branding for this Event: override else Company. --}}
        <div class="branding-preview" @if ($branding->hasPrimaryColour()) style="--brand: {{ $branding->primaryColour }}" @endif>
            @if ($branding->hasPoster())
                <img class="brand-poster" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->posterPath) }}" alt="Event poster">
            @endif
            @if ($branding->hasLogo())
                <img class="brand-logo" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}" alt="Event logo">
            @endif
            @if ($branding->hasPrimaryColour())
                <p data-primary-colour="{{ $branding->primaryColour }}">Effective colour: {{ $branding->primaryColour }}</p>
            @endif
        </div>

        <form method="POST" action="{{ route('dashboard.branding.event.update', $event) }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="logo">Logo override</label>
                <input type="file" name="logo" id="logo" accept="image/*">
                <span class="field-hint">Overrides the company logo for this event only. Leave blank to inherit.</span>
                @error('logo')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="poster">Poster / hero image</label>
                <input type="file" name="poster" id="poster" accept="image/*">
                <span class="field-hint">A wide hero image for this event's page (roughly 3:1, ~1600&times;540px). Overrides the storefront poster for this event. Max 8&nbsp;MB.</span>
                @error('poster')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="primary_colour">Primary colour override</label>
                <input type="text" name="primary_colour" id="primary_colour"
                       value="{{ old('primary_colour', $event->primary_colour) }}" placeholder="#2563eb">
                @error('primary_colour')<p class="error">{{ $message }}</p>@enderror
            </div>

            <fieldset class="field">
                <legend>Custom ticket fields override</legend>
                @php($fields = is_array($event->ticket_field_defs) ? array_values($event->ticket_field_defs) : [])
                @for ($i = 0; $i < 5; $i++)
                    <input type="text" name="ticket_field_defs[]"
                           value="{{ $fields[$i]['label'] ?? '' }}" placeholder="Field label">
                @endfor
                @error('ticket_field_defs.*')<p class="error">{{ $message }}</p>@enderror
            </fieldset>

            <button type="submit" class="btn">Save event branding</button>
        </form>
    </section>
@endsection
