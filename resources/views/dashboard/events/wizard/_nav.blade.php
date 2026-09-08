{{--
    Wizard nav: Back / Skip / Continue-Finish (Req 4.8). Rendered inside the
    step's advance <form>, so the Continue button submits it. Shared between the
    default single-form steps and the "bare" tickets step.

    In scope (from the wizard layout / controller):
      $event, $currentStep, $prevStep, $nextStep, plus the `wizard_skip` section
      flag on optional steps.
--}}
<div class="wizard-nav">
    @if ($prevStep)
        <a class="btn btn-outline"
           href="{{ route('dashboard.events.wizard.step', ['event' => $event, 'step' => $prevStep]) }}">
            Back
        </a>
    @endif

    @hasSection('wizard_skip')
        {{-- Skip: a plain GET to the next step. Optional steps only
             (venue/tickets/branding/sponsors, Req 4.8). The draft is already
             persisted, so skipping loses nothing entered so far. --}}
        @if ($nextStep)
            <a class="btn btn-outline wizard-skip"
               href="{{ route('dashboard.events.wizard.step', ['event' => $event, 'step' => $nextStep]) }}">
                Skip for now
            </a>
        @endif
    @endif

    <button type="submit" class="btn wizard-continue">
        {{ $nextStep ? 'Continue' : 'Finish' }}
    </button>
</div>
