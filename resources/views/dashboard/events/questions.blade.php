{{--
    Attendee questions screen: configure up to three custom questions asked of
    the Customer at checkout. The whole set is edited here and saved atomically
    by EventQuestionController::save().

    Expects:
      $event        — the Event being managed.
      $questions    — the Event's current questions (0–3), in position order.
      $maxQuestions — the ceiling (EventQuestion::MAX_PER_EVENT).
--}}
@extends('layouts.event')

@section('active_section', 'questions')

@php
    use App\Models\EventQuestion;

    // Pad the current set out to the fixed number of editable slots so the form
    // always shows every slot. old() (after a validation error) wins over the
    // stored value so the organiser doesn't lose their edits.
    $types = [
        EventQuestion::TYPE_FREE_TEXT => 'Free text',
        EventQuestion::TYPE_SELECT => 'Multiple choice (single answer)',
        EventQuestion::TYPE_NUMBER => 'Number',
    ];

    $existing = $questions->values();
    $slots = [];
    for ($i = 0; $i < $maxQuestions; $i++) {
        $q = $existing->get($i);
        $slots[$i] = [
            'type' => old("questions.$i.type", $q?->type ?? EventQuestion::TYPE_FREE_TEXT),
            'label' => old("questions.$i.label", $q?->label ?? ''),
            'required' => (bool) old("questions.$i.required", $q?->required ?? false),
            'options' => old(
                "questions.$i.options",
                $q && $q->isSelect() ? implode("\n", $q->choices()) : ''
            ),
        ];
    }
@endphp

@section('section')
    <div class="panel form-panel">
        <div class="panel__head">
            <h2>Attendee questions</h2>
        </div>

        <p class="hint">
            Ask buyers up to {{ $maxQuestions }} questions when they check out — for example
            dietary needs, a t-shirt size, or how they heard about the event. Leave a
            question blank to remove it. Answers appear in the
            <a href="{{ route('dashboard.events.questions.report', $event) }}">answers report</a>.
        </p>

        @if (session('status'))
            <div class="alert-success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-error" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('dashboard.events.questions.save', $event) }}" class="stack" id="questions-form">
            @csrf
            @method('PUT')

            @foreach ($slots as $i => $slot)
                <fieldset class="question-slot" data-question-slot>
                    <legend>Question {{ $i + 1 }} <span class="muted">(optional)</span></legend>

                    <div class="field">
                        <label for="questions_{{ $i }}_label">Question</label>
                        <input type="text" id="questions_{{ $i }}_label"
                               name="questions[{{ $i }}][label]"
                               value="{{ $slot['label'] }}" maxlength="255"
                               placeholder="e.g. Any dietary requirements?">
                        @error("questions.$i.label") <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="field">
                        <label for="questions_{{ $i }}_type">Answer type</label>
                        <select id="questions_{{ $i }}_type"
                                name="questions[{{ $i }}][type]"
                                data-question-type>
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}" @selected($slot['type'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error("questions.$i.type") <p class="error">{{ $message }}</p> @enderror
                    </div>

                    {{-- Options apply only to a multiple-choice question. Shown
                         and hidden client-side as the type changes; the server
                         ignores them for non-select types either way. --}}
                    <div class="field" data-question-options
                         @if ($slot['type'] !== EventQuestion::TYPE_SELECT) hidden @endif>
                        <label for="questions_{{ $i }}_options">Choices <span class="muted">(one per line)</span></label>
                        <textarea id="questions_{{ $i }}_options"
                                  name="questions[{{ $i }}][options]"
                                  rows="4" maxlength="2000"
                                  placeholder="Vegetarian&#10;Vegan&#10;No requirements">{{ $slot['options'] }}</textarea>
                        <p class="hint">At least two choices. The buyer picks one.</p>
                        @error("questions.$i.options") <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <label class="consent">
                        <input type="hidden" name="questions[{{ $i }}][required]" value="0">
                        <input type="checkbox" name="questions[{{ $i }}][required]" value="1"
                               {{ $slot['required'] ? 'checked' : '' }}>
                        <span>Require an answer</span>
                    </label>
                </fieldset>
            @endforeach

            <div class="form-actions">
                <button type="submit" class="btn">Save questions</button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        var SELECT_TYPE = @json(EventQuestion::TYPE_SELECT);

        // Show the choices textarea only when the slot's answer type is the
        // multiple-choice option; hide it otherwise.
        document.querySelectorAll('[data-question-slot]').forEach(function (slot) {
            var typeEl = slot.querySelector('[data-question-type]');
            var optionsEl = slot.querySelector('[data-question-options]');
            if (!typeEl || !optionsEl) return;

            function sync() {
                optionsEl.hidden = typeEl.value !== SELECT_TYPE;
            }

            typeEl.addEventListener('change', sync);
            sync();
        });
    })();
</script>
@endpush
