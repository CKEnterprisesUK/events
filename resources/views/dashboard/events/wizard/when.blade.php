{{--
    Wizard step 2: When (Req 4.3). The event start datetime. Posts to
    `events.wizard.save` step `when`. Persistence + not-in-past validation land
    in task 7.3; here the field submits to the right route and prefills from the
    draft. This step is optional (a date can be added later), so Skip is shown.
--}}
@extends('dashboard.events.wizard._layout')

@section('wizard_skip', true)

@section('wizard_form_open')
    <form method="POST"
          action="{{ route('dashboard.events.wizard.save', ['event' => $event, 'step' => $currentStep]) }}"
          class="stack">
@endsection

@section('wizard_panel')
    @php
        // Floor the browser `min` at the stored value if it is already in the
        // past, so an existing draft date can still be re-saved.
        $nowLocal = now()->format('Y-m-d\TH:i');
        $storedLocal = $event?->starts_at?->format('Y-m-d\TH:i');
        $startMin = ($storedLocal !== null && $storedLocal < $nowLocal) ? $storedLocal : $nowLocal;
    @endphp
    <div class="field">
        <label for="starts_at">Starts at</label>
        <input id="starts_at" type="datetime-local" name="starts_at" min="{{ $startMin }}"
               value="{{ old('starts_at', $storedLocal) }}">
        <span class="field-hint">The event can’t start in the past.</span>
        @error('starts_at') <p class="error">{{ $message }}</p> @enderror
    </div>
@endsection

@section('wizard_help')
    <h2 class="wizard-help__title">When does it happen?</h2>
    <p>Set the date and time your event starts. Customers see this on your event page and on their tickets.</p>
    <p>The start time also acts as the default end of ticket sales — tickets stop selling once the event begins unless you set your own sale window later.</p>
    <p>Not sure yet? You can skip this and add it before you publish.</p>
@endsection
