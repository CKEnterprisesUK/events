{{--
    Availability control (Req 7) — the three-way capacity_mode chooser.

    In scope:
      $ticketType — ?TicketType (null for a new/blank accordion row)

    Renders three .choice radios bound to capacity_mode:
      - "Limited quantity" => capped      (reveals the quantity input)
      - "Use event capacity" => shared_pool
      - "Unlimited" => unlimited
    The quantity input is shown (and `required`) only for the capped mode; the
    accordion/control JS enables/disables it as the selection changes so a hidden
    quantity is never submitted for shared_pool/unlimited. (Req 7.1–7.5)
--}}
@php
    $ticketType = $ticketType ?? null;
    $mode = old('capacity_mode', $ticketType?->capacity_mode ?? \App\Models\TicketType::MODE_CAPPED);
@endphp
<fieldset class="field ticket-form__availability">
    <legend>Availability</legend>

    <label class="choice"><input type="radio" name="capacity_mode" value="capped" data-availability
        @checked($mode === \App\Models\TicketType::MODE_CAPPED)> Limited quantity</label>
    <label class="choice"><input type="radio" name="capacity_mode" value="shared_pool" data-availability
        @checked($mode === \App\Models\TicketType::MODE_SHARED_POOL)> Use event capacity</label>
    <label class="choice"><input type="radio" name="capacity_mode" value="unlimited" data-availability
        @checked($mode === \App\Models\TicketType::MODE_UNLIMITED)> Unlimited</label>

    <div class="field" data-capacity-field @if ($mode !== \App\Models\TicketType::MODE_CAPPED) hidden @endif>
        <label>Quantity</label>
        <input type="number" name="capacity" min="1" max="1000000"
               @if ($mode === \App\Models\TicketType::MODE_CAPPED) required @endif
               value="{{ old('capacity', $ticketType?->capacity) }}">
        @error('capacity') <p class="error">{{ $message }}</p> @enderror
    </div>
</fieldset>
