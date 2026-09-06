{{--
    "Branding" screen for one Event: logo/poster/colour overrides and the
    printed-ticket instructions. Sponsors have their own dedicated screen
    (dashboard.events.sponsors). Rendered as a section of the manage-event
    layout so it sits in the section nav alongside Overview, Where, Tickets etc.
--}}
@extends('layouts.event')

@section('active_section', 'branding')

@section('section')
    <div class="stack">
        <div>
            <h2 style="margin:0 0 .35rem;">Branding</h2>
            <p class="muted" style="margin:0;">Leave a field blank to inherit the company-level branding.</p>
        </div>

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

        <form method="POST" action="{{ route('dashboard.branding.event.update', $event) }}" enctype="multipart/form-data" class="stack">
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

            <hr>

            <h3 style="margin:0;">Ticket design</h3>
            <p class="muted" style="margin:0;">Custom instructions printed on the downloadable A4 e-ticket. Manage which sponsors appear on the ticket from the <a href="{{ route('dashboard.events.sponsors', $event) }}">Sponsors</a> screen.</p>

            <div class="field">
                <label for="ticket_instructions">Custom instructions</label>
                <textarea name="ticket_instructions" id="ticket_instructions" rows="4"
                          maxlength="2000">{{ old('ticket_instructions', $event->ticket_instructions) }}</textarea>
                <span class="field-hint">Printed near the bottom of the ticket (e.g. entry conditions). Leave blank to use the default wording.</span>
                @error('ticket_instructions')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">Save event branding</button>
            </div>
        </form>
    </div>
@endsection
