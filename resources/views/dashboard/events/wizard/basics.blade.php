{{--
    Wizard step 1: Basics (Req 4.2). Name + description. This is the ONLY step
    that posts to `events.wizard.store` — it creates the draft event and the
    controller advances to `when`. $event is null here, so there is no Back link
    and no persisted values to prefill (old() only, after a validation bounce).
--}}
@extends('dashboard.events.wizard._layout')

@section('wizard_form_open')
    <form method="POST" action="{{ route('dashboard.events.wizard.store') }}" class="stack">
@endsection

@section('wizard_panel')
    <div class="field">
        <label for="name">Event name</label>
        <input id="name" type="text" name="name" required value="{{ old('name') }}"
               placeholder="e.g. Summer Music Festival">
        @error('name') <p class="error">{{ $message }}</p> @enderror
    </div>

    <div class="field">
        <label for="description">Description <span class="muted">(optional)</span></label>
        <textarea id="description" name="description" rows="5">{{ old('description') }}</textarea>
        <p class="hint">A short summary shown on your public event page. You can refine this later.</p>
        @error('description') <p class="error">{{ $message }}</p> @enderror
    </div>
@endsection

@section('wizard_help')
    <h2 class="wizard-help__title">Start with the essentials</h2>
    <p>Give your event a clear, recognisable name — it's what customers see first in listings and on their tickets.</p>
    <p>The description is optional for now. Continuing creates a <strong>draft</strong> event you can keep editing; nothing goes public until you publish it.</p>
@endsection
