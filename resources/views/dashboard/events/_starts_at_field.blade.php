{{--
    Start date + time control for an Event.

    Presents two clearly labelled controls — a date picker and a time picker —
    that are combined, client-side, into a single hidden `starts_at` input in
    the `YYYY-MM-DDTHH:MM` shape the backend already accepts (parsed with
    Carbon). This is a pure UI/progressive-enhancement change: the persisted
    field, its validation ("can't start in the past") and the submitted value
    are all unchanged.

    Without JavaScript, the two visible inputs are ignored and a native
    datetime-local input (inside <noscript>) carries the value instead, so the
    field still works.

    Params:
      $event    — the Event (nullable, e.g. on create).
      $required — whether the control is required (default false).
      $label    — the group label (default "Start date and time").
--}}
@php
    $event = $event ?? null;
    $required = $required ?? false;
    $label = $label ?? 'Start date and time';

    // Floor the min at the stored value when it's already in the past, so an
    // existing draft date can still be re-saved (mirrors the old field).
    $nowLocal = now()->format('Y-m-d\TH:i');
    $storedLocal = $event?->starts_at?->format('Y-m-d\TH:i');
    $minLocal = ($storedLocal !== null && $storedLocal < $nowLocal) ? $storedLocal : $nowLocal;

    // Seed the visible inputs from old() (a rejected submit) or the stored value.
    $submitted = old('starts_at', $storedLocal);
    $dateValue = $submitted ? \Illuminate\Support\Str::before($submitted, 'T') : '';
    $timeValue = $submitted ? \Illuminate\Support\Str::after($submitted, 'T') : '';
    $minDate = \Illuminate\Support\Str::before($minLocal, 'T');

    // Human-readable UK summary (e.g. "11 Sep 2026 · 19:59"), shown when set.
    $humanValue = $submitted ? \Illuminate\Support\Carbon::parse($submitted)->format('j M Y · H:i') : '';
@endphp

<div class="field datetime-field" data-datetime-field>
    <span class="field-group-label">
        {{ $label }}
        @unless ($required) <span class="muted">(optional)</span> @endunless
    </span>

    {{-- Enhanced control: separate date + time, combined into the hidden input
         below on change/submit. These inputs carry no `name`, so they never
         submit directly — the hidden `starts_at` below is authoritative. --}}
    <div class="datetime-field__inputs">
        <div class="datetime-field__part">
            <label for="starts_at_date">Date</label>
            <input type="date" id="starts_at_date" min="{{ $minDate }}"
                   value="{{ $dateValue }}" data-date {{ $required ? 'required' : '' }}
                   autocomplete="off">
        </div>
        <div class="datetime-field__part">
            <label for="starts_at_time">Time</label>
            <input type="time" id="starts_at_time"
                   value="{{ $timeValue }}" data-time {{ $required ? 'required' : '' }}
                   autocomplete="off">
        </div>
    </div>

    {{-- The value the server reads. Populated from the two inputs by JS. Seeded
         with the current value so a no-JS-change submit still carries it. --}}
    <input type="hidden" name="starts_at" value="{{ $submitted }}" data-combined>

    {{-- No-JS fallback: a plain native combined control that owns `starts_at`
         directly. Removed from the DOM by JS so it can't double-submit. --}}
    <noscript>
        <input type="datetime-local" name="starts_at" min="{{ $minLocal }}"
               value="{{ $submitted }}" {{ $required ? 'required' : '' }}>
    </noscript>

    <span class="field-hint" data-datetime-summary @if ($humanValue === '') hidden @endif>
        {{ $humanValue !== '' ? 'Starts ' . $humanValue : '' }}
    </span>
    <span class="field-hint">The event can’t start in the past.</span>
    @error('starts_at') <p class="error">{{ $message }}</p> @enderror
</div>

@once
    @push('scripts')
    <script>
        (function () {
            // Wire the split date/time inputs to the hidden `starts_at` field.
            document.querySelectorAll('[data-datetime-field]').forEach(function (field) {
                var dateEl = field.querySelector('[data-date]');
                var timeEl = field.querySelector('[data-time]');
                var combined = field.querySelector('[data-combined]');
                var summary = field.querySelector('[data-datetime-summary]');
                var noscript = field.querySelector('noscript');
                if (!dateEl || !timeEl || !combined) return;

                // Drop the no-JS native input so only the hidden field submits.
                if (noscript && noscript.parentNode) {
                    noscript.parentNode.removeChild(noscript);
                }

                var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

                function humanise(dateStr, timeStr) {
                    var parts = dateStr.split('-');
                    if (parts.length !== 3) return '';
                    var y = parts[0], m = parseInt(parts[1], 10), d = parseInt(parts[2], 10);
                    if (!m || !d) return '';
                    var t = (timeStr || '').slice(0, 5);
                    return d + ' ' + (MONTHS[m - 1] || '') + ' ' + y + (t ? ' · ' + t : '');
                }

                function sync() {
                    var d = dateEl.value;
                    var t = timeEl.value;

                    // Default the time to a sensible value if a date is picked
                    // without one, so a partial entry still submits cleanly.
                    if (d && !t) { t = '09:00'; }

                    combined.value = d ? (d + 'T' + t) : '';

                    if (summary) {
                        var text = humanise(d, t);
                        if (text) {
                            summary.textContent = 'Starts ' + text;
                            summary.hidden = false;
                        } else {
                            summary.textContent = '';
                            summary.hidden = true;
                        }
                    }
                }

                dateEl.addEventListener('change', sync);
                dateEl.addEventListener('input', sync);
                timeEl.addEventListener('change', sync);
                timeEl.addEventListener('input', sync);

                // Ensure the combined value is right at submit time even if the
                // change events didn't fire (e.g. autofill).
                var form = field.closest('form');
                if (form) { form.addEventListener('submit', sync); }

                sync();
            });
        })();
    </script>
    @endpush
@endonce
