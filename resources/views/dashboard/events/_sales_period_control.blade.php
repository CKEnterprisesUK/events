{{--
    Sales-period control (Req 8) — two default-checked "use default" checkboxes,
    each revealing an optional datetime input.

    In scope:
      $ticketType — ?TicketType (null for a new/blank accordion row)
      $event      — ?Event (used to spell out when the default close happens)

    Semantics (new effective-interval model): a null saved bound means "unbounded
    on that side" (publication is the effective lower bound, event start the
    effective upper bound). So a saved null bound => the matching "use default"
    checkbox is checked. When a checkbox is checked its datetime input is both
    `hidden` AND `disabled`, so it is not submitted and the controller persists
    null. Unchecking reveals + enables the input. (Req 8.1–8.6, 8.9)

    Layout: "Sales open" and "Sales close" are separate, visually boxed groups,
    each holding ONLY its own checkbox, hint and date input. Previously the
    start-date input sat directly above the "Stop selling…" checkbox, so a start
    date read as if it were the end date. Each group also states in words what
    will happen while its checkbox is ticked (e.g. the actual close time).
--}}
@php
    $ticketType = $ticketType ?? null;
    $event = $event ?? null;
    // A saved null bound => the corresponding "use default" checkbox is checked.
    $startDefault = old('sale_starts_default', $ticketType ? ($ticketType->sale_starts_at === null) : true);
    $endDefault   = old('sale_ends_default',   $ticketType ? ($ticketType->sale_ends_at === null)   : true);

    $eventStartsLabel = $event?->starts_at?->format('D j M Y, H:i');
@endphp
<fieldset class="field ticket-form__sales">
    <legend>Sales period</legend>

    <div class="ticket-form__sales-group" data-sales-group>
        <p class="ticket-form__sales-title">Sales open</p>
        <label class="consent">
            <input type="checkbox" name="sale_starts_default" value="1" data-sales-start-default @checked($startDefault)>
            Start selling when the event goes live
        </label>
        <p class="hint" data-sales-hint @unless ($startDefault) hidden @endunless>
            Tickets go on sale as soon as the event is published.
        </p>
        <div class="field" data-sales-start @if ($startDefault) hidden @endif>
            <label>
                Start selling at
                <input type="datetime-local" name="sale_starts_at" @disabled($startDefault)
                       value="{{ old('sale_starts_at', $ticketType?->sale_starts_at?->format('Y-m-d\TH:i')) }}">
            </label>
            @error('sale_starts_at') <p class="error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="ticket-form__sales-group" data-sales-group>
        <p class="ticket-form__sales-title">Sales close</p>
        <label class="consent">
            <input type="checkbox" name="sale_ends_default" value="1" data-sales-end-default @checked($endDefault)>
            Stop selling when the event starts
        </label>
        <p class="hint" data-sales-hint @unless ($endDefault) hidden @endunless>
            @if ($eventStartsLabel)
                Sales close automatically at the event start time ({{ $eventStartsLabel }}).
            @else
                Sales close automatically at the event start time. Set a start time for the event to fix when that is.
            @endif
        </p>
        <div class="field" data-sales-end @if ($endDefault) hidden @endif>
            <label>
                Stop selling at
                <input type="datetime-local" name="sale_ends_at" @disabled($endDefault)
                       value="{{ old('sale_ends_at', $ticketType?->sale_ends_at?->format('Y-m-d\TH:i')) }}">
            </label>
            <p class="hint">Must be no later than the event start time.</p>
            @error('sale_ends_at') <p class="error">{{ $message }}</p> @enderror
        </div>
    </div>
</fieldset>
