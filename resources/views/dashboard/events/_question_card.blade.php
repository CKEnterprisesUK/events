{{--
    One attendee-question row: a compact bordered card (matching the app's
    restrained section style — no fieldset/legend as the visual container) with
    two views toggled by JS:

      - summary: "1. Any dietary requirements?  Free text · Required" + Edit/Delete
      - editor:  Question / Answer type / (Choices) / Require + Done/Delete

    A brand-new (or error-reopened) row starts in the editor view. All fields
    post as questions[$index][...] so the whole set submits together to
    EventQuestionController::save().

    Params:
      $index      — the row index (int, or "__INDEX__" in the template).
      $row        — ['type','label','required','options','answers_count','open'].
      $types      — [value => label] answer-type options.
      $isTemplate — true when rendered inside the <template> (skip @error).
--}}
@php
    use App\Models\EventQuestion;
    $isTemplate = $isTemplate ?? false;
    $open = (bool) ($row['open'] ?? false);
    $answers = (int) ($row['answers_count'] ?? 0);
    $num = is_int($index) ? $index + 1 : 1;
@endphp

<div class="q-card {{ $open ? 'is-editing' : '' }}" data-question-card data-answers="{{ $answers }}">
    {{-- Summary view --}}
    <div class="q-card__summary" data-summary @if ($open) hidden @endif>
        <div class="q-card__summary-main">
            <span class="q-card__num" data-question-num>Question {{ $num }}</span>
            <span class="q-card__label" data-summary-label>{{ $row['label'] !== '' ? $row['label'] : 'Untitled question' }}</span>
            <span class="q-card__meta" data-summary-meta>
                {{ $types[$row['type']] ?? 'Free text' }} · {{ $row['required'] ? 'Required' : 'Optional' }}
            </span>
        </div>
        <div class="q-card__actions">
            <button type="button" class="btn btn-outline btn-sm" data-edit>Edit</button>
            <button type="button" class="btn btn-outline btn-sm q-card__delete" data-delete>Delete</button>
        </div>
    </div>

    {{-- Editor view --}}
    <div class="q-card__editor" data-editor @unless ($open) hidden @endunless>
        <div class="q-card__editor-head">
            <span class="q-card__num" data-question-num>Question {{ $num }}</span>
        </div>

        <div class="field">
            <label for="q_{{ $index }}_label">Question</label>
            <input type="text" id="q_{{ $index }}_label"
                   name="questions[{{ $index }}][label]"
                   value="{{ $row['label'] }}" maxlength="255"
                   placeholder="e.g. Any dietary requirements?">
            @unless ($isTemplate)
                @error("questions.$index.label") <p class="error">{{ $message }}</p> @enderror
            @endunless
        </div>

        <div class="field">
            <label for="q_{{ $index }}_type">Answer type</label>
            <select id="q_{{ $index }}_type" name="questions[{{ $index }}][type]" data-question-type>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected($row['type'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @unless ($isTemplate)
                @error("questions.$index.type") <p class="error">{{ $message }}</p> @enderror
            @endunless
        </div>

        {{-- Choices only apply to a multiple-choice question. --}}
        <div class="field" data-question-options @if ($row['type'] !== EventQuestion::TYPE_SELECT) hidden @endif>
            <label for="q_{{ $index }}_options">Choices <span class="muted">(one per line)</span></label>
            <textarea id="q_{{ $index }}_options" name="questions[{{ $index }}][options]"
                      rows="4" maxlength="2000"
                      placeholder="Vegetarian&#10;Vegan&#10;No requirements">{{ $row['options'] }}</textarea>
            <p class="hint">At least two choices. The buyer picks one.</p>
            @unless ($isTemplate)
                @error("questions.$index.options") <p class="error">{{ $message }}</p> @enderror
            @endunless
        </div>

        <label class="consent">
            <input type="hidden" name="questions[{{ $index }}][required]" value="0">
            <input type="checkbox" name="questions[{{ $index }}][required]" value="1"
                   {{ $row['required'] ? 'checked' : '' }}>
            <span>Require an answer</span>
        </label>

        <div class="q-card__editor-actions">
            <button type="button" class="btn btn-sm" data-done>Save question</button>
            <button type="button" class="btn btn-outline btn-sm q-card__delete" data-delete>Delete</button>
        </div>
    </div>
</div>
