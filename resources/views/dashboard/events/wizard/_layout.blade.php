{{--
    Shared wizard shell for the multi-step create flow (Req 4, Design:
    "Multi-step create wizard"). Extends the dashboard layout and provides:

      - `.wizard-steps` — the progress indicator built from $steps, marking the
        $currentStep active and every prior step complete (reuses the existing
        .wizard-step / .wizard-step-num / .is-active / .is-complete CSS).
      - `.wizard-panel` — the current step's field content (@yield('wizard_panel')).
      - `.wizard-help` — the contextual help aside (@yield('wizard_help'), Req 4.12).
      - `.wizard-nav` — Back / Skip / Continue-Finish. The step's form owns the
        submit button, so each step renders its own <form> wrapping the panel and
        the nav's Continue/Finish button; Back and Skip are plain links rendered
        here from $prevStep / the step's optionality.

    Per-step views @extend this layout and fill:
      - @section('wizard_form_open')  — the opening <form ...> tag (method/action/enctype)
      - @section('wizard_panel')      — the step's fields
      - @section('wizard_help')       — the contextual help body
      - @section('wizard_skip', true) — set truthy on optional steps to show Skip

    Shared view vars (from EventWizardController::renderStep):
      $event (null on basics), $steps, $currentStep, $prevStep, $nextStep, $readiness.

    NOTE: per-step persistence + progressive validation are task 7.3; here the
    forms simply submit to the right routes.
--}}
@extends('layouts.dashboard')

@php
    // Human labels + step numbers for the progress indicator. Only the steps in
    // $steps are shown, so branding/sponsors are naturally dropped for users
    // without the `settings` permission (the controller filters them).
    $stepLabels = [
        'basics' => 'Basics',
        'when' => 'When',
        'venue' => 'Venue',
        'tickets' => 'Tickets',
        'branding' => 'Branding',
        'sponsors' => 'Sponsors',
    ];
    $currentIndex = array_search($currentStep, $steps, true);
@endphp

@section('title', 'Create event')

@section('content')
    <div class="page-head">
        <div>
            <h1 style="margin:0;">Create event</h1>
            <p class="muted" style="margin:.35rem 0 0;">
                Step {{ $currentIndex !== false ? $currentIndex + 1 : 1 }} of {{ count($steps) }} &mdash; {{ $stepLabels[$currentStep] ?? ucfirst($currentStep) }}
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.index') }}">Cancel</a>
        </div>
    </div>

    {{-- Progress indicator: steps before the current one are complete, the
         current one is active, later ones are plain. Built from $steps so it
         only ever shows reachable steps. --}}
    <ol class="wizard-steps" aria-label="Create event progress">
        @foreach ($steps as $i => $slug)
            @php
                $isActive = $slug === $currentStep;
                $isComplete = $currentIndex !== false && $i < $currentIndex;
            @endphp
            <li class="wizard-step {{ $isActive ? 'is-active' : '' }} {{ $isComplete ? 'is-complete' : '' }}"
                @if ($isActive) aria-current="step" @endif>
                <span class="wizard-step-num">{{ $i + 1 }}</span>
                <span class="wizard-step-label">{{ $stepLabels[$slug] ?? ucfirst($slug) }}</span>
            </li>
        @endforeach
    </ol>

    <div class="wizard-shell">
        <div class="panel form-panel wizard-main">
            @hasSection('wizard_panel_bare')
                {{-- Bare mode (tickets step): the panel manages its own <form>s
                     (e.g. the ticket accordion's per-row forms), which cannot be
                     nested inside another form. The panel therefore renders
                     OUTSIDE the wizard form, and only the Back/Skip/Continue nav
                     is wrapped in its own minimal advance form. --}}
                <div class="wizard-panel">
                    @yield('wizard_panel_bare')
                </div>

                <form method="POST"
                      action="{{ route('dashboard.events.wizard.save', ['event' => $event, 'step' => $currentStep]) }}">
                    @csrf
                    @include('dashboard.events.wizard._nav')
                </form>
            @else
                {{-- Default mode: the step owns one <form> wrapping its fields and
                     the nav, so Continue submits the field values. The opening
                     tag (method/action/enctype) is supplied per step. --}}
                @yield('wizard_form_open')
                    @csrf
                    @yield('wizard_method')

                    <div class="wizard-panel">
                        @yield('wizard_panel')
                    </div>

                    @include('dashboard.events.wizard._nav')
                </form>
            @endif
        </div>

        <aside class="wizard-help" aria-label="Step help">
            <div class="wizard-help__inner">
                @yield('wizard_help')
            </div>
        </aside>
    </div>
@endsection
