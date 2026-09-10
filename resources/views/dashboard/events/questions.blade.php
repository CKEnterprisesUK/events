{{--
    Attendee questions screen: manage up to three custom questions asked of the
    Customer at checkout, using an add/manage pattern.

    Existing questions show as compact summary rows (label + type/required +
    Edit/Delete). "Add question" appends an inline editor. Everything sits in
    one form and is saved atomically by EventQuestionController::save(), which
    replaces the whole set — so a removed row is simply one that isn't
    submitted. Deleting a question that already holds answers asks for
    confirmation; the answers themselves keep a snapshot label and null their FK
    on delete, so the answers report is never broken.

    Expects:
      $event        — the Event being managed.
      $questions    — the Event's current questions (0–3), each with
                      `answers_count` (via withCount).
      $maxQuestions — the ceiling (EventQuestion::MAX_PER_EVENT).
--}}
@extends('layouts.event')

@section('active_section', 'questions')

@php
    use App\Models\EventQuestion;

    $types = [
        EventQuestion::TYPE_FREE_TEXT => 'Free text',
        EventQuestion::TYPE_SELECT => 'Multiple choice',
        EventQuestion::TYPE_NUMBER => 'Number',
    ];

    // Rebuild the editable rows. After a validation error the submitted set
    // (old('questions')) is authoritative so nothing the organiser typed is
    // lost; otherwise use the stored questions. Each row tracks whether it
    // already has collected answers (delete needs confirmation then).
    $old = old('questions');
    if (is_array($old)) {
        $rows = [];
        foreach (array_values($old) as $row) {
            $rows[] = [
                'type' => $row['type'] ?? EventQuestion::TYPE_FREE_TEXT,
                'label' => $row['label'] ?? '',
                'required' => (bool) ($row['required'] ?? false),
                'options' => is_string($row['options'] ?? null) ? $row['options'] : '',
                'answers_count' => 0,
                'open' => true, // reopen editors so errors are visible/fixable
            ];
        }
    } else {
        $rows = $questions->map(fn ($q) => [
            'type' => $q->type,
            'label' => $q->label,
            'required' => (bool) $q->required,
            'options' => $q->isSelect() ? implode("\n", $q->choices()) : '',
            'answers_count' => (int) ($q->answers_count ?? 0),
            'open' => false,
        ])->all();
    }

    $typeLabels = $types;
@endphp

@section('section')
    <div class="panel form-panel">
        <div class="panel__head">
            <h2>Attendee questions</h2>
        </div>

        <div class="stack">
            <p class="hint" style="margin-top:0.25rem;">
                Ask buyers questions during checkout. Answers appear in the
                <a href="{{ route('dashboard.events.questions.report', $event) }}">answers report</a>.
                Maximum of {{ $maxQuestions }} questions.
            </p>

            @if (session('status'))
                <p class="status" role="status">{{ session('status') }}</p>
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

            <form method="POST" action="{{ route('dashboard.events.questions.save', $event) }}" id="questions-form"
                  data-questions-form data-max="{{ $maxQuestions }}">
                @csrf
                @method('PUT')

                <div class="q-list" data-question-list>
                    @foreach ($rows as $i => $row)
                        @include('dashboard.events._question_card', [
                            'index' => $i,
                            'row' => $row,
                            'types' => $typeLabels,
                            'errors' => $errors,
                        ])
                    @endforeach
                </div>

                {{-- Empty state when there are no questions yet. --}}
                <p class="q-empty" data-question-empty @if (count($rows) > 0) hidden @endif>
                    No questions yet.
                </p>

                <div class="q-actions">
                    <button type="button" class="btn btn-outline" data-add-question>+ Add question</button>
                    <span class="q-max-note" data-max-note hidden>Maximum {{ $maxQuestions }} questions.</span>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn" data-save-questions>Save questions</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Template for a brand-new question editor. Its `name`s use the __INDEX__
         placeholder, swapped for the next free index when cloned by JS. --}}
    <template data-question-template>
        @include('dashboard.events._question_card', [
            'index' => '__INDEX__',
            'row' => ['type' => EventQuestion::TYPE_FREE_TEXT, 'label' => '', 'required' => false, 'options' => '', 'answers_count' => 0, 'open' => true],
            'types' => $typeLabels,
            'errors' => $errors,
            'isTemplate' => true,
        ])
    </template>
@endsection

@push('scripts')
<script>
    (function () {
        var SELECT_TYPE = @json(EventQuestion::TYPE_SELECT);
        var TYPE_LABELS = @json($typeLabels);

        var form = document.querySelector('[data-questions-form]');
        if (!form) return;

        var list = form.querySelector('[data-question-list]');
        var template = document.querySelector('[data-question-template]');
        var addBtn = form.querySelector('[data-add-question]');
        var maxNote = form.querySelector('[data-max-note]');
        var emptyMsg = form.querySelector('[data-question-empty]');
        var max = parseInt(form.getAttribute('data-max'), 10) || 3;

        function cards() {
            return Array.prototype.slice.call(list.querySelectorAll('[data-question-card]'));
        }

        // Renumber the visible "Question N" titles and keep name indices unique
        // and contiguous, so the whole set posts as questions[0..n].
        function reindex() {
            cards().forEach(function (card, i) {
                var title = card.querySelector('[data-question-num]');
                if (title) { title.textContent = 'Question ' + (i + 1); }
                card.querySelectorAll('[name^="questions["]').forEach(function (el) {
                    el.name = el.name.replace(/questions\[[^\]]*\]/, 'questions[' + i + ']');
                });
            });
        }

        function refreshControls() {
            var count = cards().length;
            if (addBtn) { addBtn.hidden = count >= max; }
            if (maxNote) { maxNote.hidden = count < max; }
            if (emptyMsg) { emptyMsg.hidden = count > 0; }
        }

        // Toggle a card between its summary and editor views, refreshing the
        // summary text from the current field values when collapsing.
        function setOpen(card, open) {
            card.classList.toggle('is-editing', open);
            var summary = card.querySelector('[data-summary]');
            var editor = card.querySelector('[data-editor]');
            if (summary) { summary.hidden = open; }
            if (editor) { editor.hidden = !open; }
            if (!open) { updateSummary(card); }
        }

        function updateSummary(card) {
            var labelEl = card.querySelector('[name$="[label]"]');
            var typeEl = card.querySelector('[data-question-type]');
            var reqEl = card.querySelector('[name$="[required]"][type="checkbox"]');

            var labelText = card.querySelector('[data-summary-label]');
            var metaText = card.querySelector('[data-summary-meta]');
            if (labelText) { labelText.textContent = (labelEl && labelEl.value.trim()) || 'Untitled question'; }
            if (metaText) {
                var typeLabel = (typeEl && TYPE_LABELS[typeEl.value]) || '';
                var req = reqEl && reqEl.checked ? 'Required' : 'Optional';
                metaText.textContent = typeLabel + ' · ' + req;
            }
        }

        // Show the choices field only for a multiple-choice question.
        function syncOptions(card) {
            var typeEl = card.querySelector('[data-question-type]');
            var optionsEl = card.querySelector('[data-question-options]');
            if (typeEl && optionsEl) { optionsEl.hidden = typeEl.value !== SELECT_TYPE; }
        }

        function wireCard(card) {
            syncOptions(card);
            var typeEl = card.querySelector('[data-question-type]');
            if (typeEl) { typeEl.addEventListener('change', function () { syncOptions(card); }); }

            card.querySelectorAll('[data-edit]').forEach(function (b) {
                b.addEventListener('click', function () { setOpen(card, true); });
            });
            card.querySelectorAll('[data-done]').forEach(function (b) {
                b.addEventListener('click', function () { setOpen(card, false); });
            });
            card.querySelectorAll('[data-delete]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var answered = parseInt(card.getAttribute('data-answers') || '0', 10);
                    if (answered > 0) {
                        var msg = 'This question already has ' + answered + ' collected ' +
                            (answered === 1 ? 'answer' : 'answers') +
                            '. Deleting it removes the question from checkout. ' +
                            'Existing answers stay in the report. Delete it?';
                        if (!window.confirm(msg)) { return; }
                    }
                    card.parentNode.removeChild(card);
                    reindex();
                    refreshControls();
                });
            });
        }

        cards().forEach(wireCard);
        refreshControls();

        if (addBtn && template) {
            addBtn.addEventListener('click', function () {
                if (cards().length >= max) { return; }
                var html = template.innerHTML.replace(/__INDEX__/g, cards().length);
                var wrap = document.createElement('div');
                wrap.innerHTML = html.trim();
                var card = wrap.firstElementChild;
                list.appendChild(card);
                wireCard(card);
                setOpen(card, true);
                reindex();
                refreshControls();
                var firstInput = card.querySelector('input, select, textarea');
                if (firstInput) { firstInput.focus(); }
            });
        }

        // Belt-and-braces: make sure indices are contiguous at submit time.
        form.addEventListener('submit', reindex);
    })();
</script>
@endpush
