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

            <hr>

            <h2>Ticket design</h2>
            <p>These appear on the downloadable A4 e-ticket. The bottom sponsor banner is also shown on your public event page and storefront.</p>

            <div class="field">
                <label for="ticket_instructions">Custom instructions</label>
                <textarea name="ticket_instructions" id="ticket_instructions" rows="4"
                          maxlength="2000">{{ old('ticket_instructions', $event->ticket_instructions) }}</textarea>
                <span class="field-hint">Printed near the bottom of the ticket (e.g. entry conditions). Leave blank to use the default wording.</span>
                @error('ticket_instructions')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="sponsor_top">Top sponsor banner</label>
                @if ($event->sponsor_top_path)
                    <img class="brand-poster" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($event->sponsor_top_path) }}" alt="Top sponsor banner">
                    <label class="consent">
                        <input type="checkbox" name="remove_sponsor_top" value="1"> Remove this banner
                    </label>
                @endif
                <input type="file" name="sponsor_top" id="sponsor_top" accept="image/*">
                <span class="field-hint">A wide landscape banner shown across the top of the ticket. Max 8&nbsp;MB.</span>
                @error('sponsor_top')<p class="error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="sponsor_bottom">Bottom sponsor banner</label>
                @if ($event->sponsor_bottom_path)
                    <img class="brand-poster" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($event->sponsor_bottom_path) }}" alt="Bottom sponsor banner">
                    <label class="consent">
                        <input type="checkbox" name="remove_sponsor_bottom" value="1"> Remove this banner
                    </label>
                @endif
                <input type="file" name="sponsor_bottom" id="sponsor_bottom" accept="image/*">
                <span class="field-hint">A wide landscape banner shown across the bottom of the ticket and on the public event page. Max 8&nbsp;MB.</span>
                @error('sponsor_bottom')<p class="error">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="btn">Save event branding</button>
        </form>
    </section>
@endsection
