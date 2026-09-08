{{--
    Sales-period control (Req 8) — two default-checked "use default" checkboxes,
    each revealing an optional datetime input.

    In scope:
      $ticketType — ?TicketType (null for a new/blank accordion row)

    Semantics (new effective-interval model): a null saved bound means "unbounded
    on that side" (publication is the effective lower bound, event start the
    effective upper bound). So a saved null bound => the matching "use default"
    checkbox is checked. When a checkbox is checked its datetime input is both
    `hidden` AND `disabled`, so it is not submitted and the controller persists
    null. Unchecking reveals + enables the input. (Req 8.1–8.6, 8.9)
--}}
@php
    $ticketType = $ticketType ?? null;
    // A saved null bound => the corresponding "use default" checkbox is checked.
    $startDefault = old('sale_starts_default', $ticketType ? ($ticketType->sale_starts_at === null) : true);
    $endDefault   = old('sale_ends_default',   $ticketType ? ($ticketType->sale_ends_at === null)   : true);
@endphp
<fieldset class="field ticket-form__sales">
    <legend>Sales period</legend>

    <label class="consent">
        <input type="checkbox" name="sale_starts_default" value="1" data-sales-start-default @checked($startDefault)>
        Start selling when the event goes live
    </label>
    <div class="field" data-sales-start @if ($startDefault) hidden @endif>
        <label>Start selling at</label>
        <input type="datetime-local" name="sale_starts_at" @disabled($startDefault)
               value="{{ old('sale_starts_at', $ticketType?->sale_starts_at?->format('Y-m-d\TH:i')) }}">
        @error('sale_starts_at') <p class="error">{{ $message }}</p> @enderror
    </div>

    <label class="consent">
        <input type="checkbox" name="sale_ends_default" value="1" data-sales-end-default @checked($endDefault)>
        Stop selling when the event starts
    </label>
    <div class="field" data-sales-end @if ($endDefault) hidden @endif>
        <label>Stop selling at</label>
        <input type="datetime-local" name="sale_ends_at" @disabled($endDefault)
               value="{{ old('sale_ends_at', $ticketType?->sale_ends_at?->format('Y-m-d\TH:i')) }}">
        @error('sale_ends_at') <p class="error">{{ $message }}</p> @enderror
    </div>
</fieldset>
