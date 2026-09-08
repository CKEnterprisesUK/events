{{--
    Temporary wizard step placeholder.

    Task 7.1 stands up the EventWizardController and its routes. The full wizard
    shell and per-step views (basics, when, venue, tickets, branding, sponsors)
    are built in task 7.2, and per-step persistence + progressive validation in
    task 7.3. This placeholder simply lets every wizard route render so the flow
    is navigable while those views are still to come.
--}}
@extends('layouts.dashboard')

@section('title', 'Create event')

@section('content')
    <div class="page-head">
        <div>
            <h1 style="margin:0;">Create event</h1>
            <p class="muted" style="margin:.35rem 0 0;">Step: {{ ucfirst($currentStep) }}</p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.index') }}">Cancel</a>
        </div>
    </div>

    <div class="panel form-panel" style="max-width:640px;">
        <div class="panel__head"><h2>{{ ucfirst($currentStep) }}</h2></div>

        @if ($currentStep === 'basics')
            {{-- Basics is the one step wired up in task 7.1: name + description
                 create the draft event and advance to the "when" step. --}}
            <form method="POST" action="{{ route('dashboard.events.wizard.store') }}" class="stack">
                @csrf
                <div class="field">
                    <label for="name">Event name</label>
                    <input id="name" type="text" name="name" required value="{{ old('name') }}">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="description">Description <span class="muted">(optional)</span></label>
                    <textarea id="description" name="description" rows="4">{{ old('description') }}</textarea>
                    @error('description') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn">Continue</button>
                </div>
            </form>
        @else
            {{-- Non-basics steps: persistence lands in task 7.3. For now, a bare
                 advance form keeps the flow routable. --}}
            <p class="hint">This step's fields are added in a later task.</p>
            <form method="POST" action="{{ route('dashboard.events.wizard.save', ['event' => $event, 'step' => $currentStep]) }}" class="stack">
                @csrf
                <div class="form-actions">
                    @if ($prevStep)
                        <a class="btn btn-outline" href="{{ route('dashboard.events.wizard.step', ['event' => $event, 'step' => $prevStep]) }}">Back</a>
                    @endif
                    <button type="submit" class="btn">{{ $nextStep ? 'Continue' : 'Finish' }}</button>
                </div>
            </form>
        @endif
    </div>
@endsection
