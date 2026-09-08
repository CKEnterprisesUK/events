{{--
    One ticket-type accordion row (Req 6) — a summary button plus an inline edit
    form. Reused for existing rows and for the hidden new-row <template>.

    In scope:
      $event          — the Event being managed
      $existing       — bool: true for a saved TicketType, false for the blank
                        new-row template
      $ticketType     — ?TicketType (the saved row, or null for the template)
      $symbol         — string currency symbol (e.g. "£")
      $eventRemaining — ?int overall event remaining (null = unlimited / no cap)

    Summary strings are derived server-side so they match the domain exactly:
      - Price:     "Free" when price_minor is 0, else $symbol + major units. (Req 6.9, 10.5)
      - Remaining: from availabilityFor($eventRemaining) per capacity mode. (Req 7.6)
      - Status:    from TicketType::saleStatusLabel() — label + pill class. (Req 6.9)

    The body form @includes the availability + sales-period controls (task 6.1),
    which each accept an optional $ticketType. Existing rows PUT to the update
    route and expose a Remove button posting to the destroy route; the blank
    template POSTs to the store route. (Req 6.1, 6.2, 6.3, 6.6, 6.10, 10.5)
--}}
@php
    $existing = $existing ?? false;
    $ticketType = $ticketType ?? null;
    $symbol = $symbol ?? '';
    $eventRemaining = $eventRemaining ?? null;

    // A stable id for aria-controls wiring. Existing rows key off the model id;
    // the blank template uses a placeholder that the clone JS can leave as-is
    // (aria-controls only needs to be unique on the live page — the cloned
    // fragment is inserted once and its ids are self-consistent within it).
    $rowId = $existing && $ticketType ? (string) $ticketType->id : 'new';

    // Summary: name.
    $name = $existing && $ticketType ? $ticketType->name : 'New ticket type';

    // Summary: price. £0 shows "Free". (Req 6.9, 10.5)
    if ($existing && $ticketType) {
        $priceLabel = $ticketType->isFree()
            ? 'Free'
            : $symbol . number_format($ticketType->price_minor / 100, 2);
    } else {
        $priceLabel = 'Free';
    }

    // Summary: remaining / total, derived from availabilityFor(). (Req 7.6)
    if ($existing && $ticketType) {
        if ($ticketType->isUnlimited()) {
            $remainingLabel = 'Unlimited';
        } elseif ($ticketType->isSharedPool()) {
            $remainingLabel = $eventRemaining === null
                ? 'Shared pool · unlimited'
                : number_format($eventRemaining) . ' shared';
        } else {
            $available = (int) $ticketType->availabilityFor($eventRemaining);
            $remainingLabel = number_format($available) . ' / ' . number_format((int) $ticketType->capacity);
        }
    } else {
        $remainingLabel = '';
    }

    // Summary: sales status pill. (Req 6.9)
    if ($existing && $ticketType) {
        $status = $ticketType->saleStatusLabel(now());
    } else {
        $status = ['label' => 'Draft', 'pill' => 'pill--draft'];
    }

    // Form target + method: existing rows update, blank template stores.
    $action = $existing && $ticketType
        ? route('dashboard.events.ticket-types.update', [$event, $ticketType])
        : route('dashboard.events.ticket-types.store', $event);
@endphp
<div class="ticket-acc__item" data-ticket-row>
    <button type="button" class="ticket-acc__summary" data-acc-toggle
            aria-expanded="false" aria-controls="tt-form-{{ $rowId }}">
        <span class="ticket-acc__name">{{ $name }}</span>
        <span class="ticket-acc__price">{{ $priceLabel }}</span>
        @if ($remainingLabel !== '')
            <span class="ticket-acc__remaining">{{ $remainingLabel }}</span>
        @endif
        <span class="pill {{ $status['pill'] }}">{{ $status['label'] }}</span>
        <x-icon name="chevron" class="ticket-acc__chev" />
    </button>

    <div class="ticket-acc__body" id="tt-form-{{ $rowId }}" hidden>
        <form method="POST" action="{{ $action }}" class="ticket-form">
            @csrf
            @if ($existing)
                @method('PUT')
            @endif

            <div class="ticket-form__grid">
                <div class="field">
                    <label>Name</label>
                    <input type="text" name="name" required maxlength="100"
                           value="{{ old('name', $existing && $ticketType ? $ticketType->name : '') }}">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field">
                    <label>Price</label>
                    <input type="number" name="price" step="0.01" min="0" max="999999.99" required
                           value="{{ old('price', $existing && $ticketType ? number_format($ticketType->price_minor / 100, 2, '.', '') : '0.00') }}">
                    <p class="hint">Enter 0 for a free ticket.</p>
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="field ticket-form__description">
                    <label>Description <span class="muted">(optional)</span></label>
                    <textarea name="description" rows="2" maxlength="1000">{{ old('description', $existing && $ticketType ? $ticketType->description : '') }}</textarea>
                    @error('description') <p class="error">{{ $message }}</p> @enderror
                </div>

                @include('dashboard.events._availability_control', ['ticketType' => $ticketType])
                @include('dashboard.events._sales_period_control', ['ticketType' => $ticketType])
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-sm">Save ticket</button>
                <button type="button" class="btn btn-outline btn-sm" data-acc-cancel>Cancel</button>
                @if ($existing && $ticketType)
                    <button type="submit" class="btn btn-danger btn-sm ticket-form__delete"
                            formaction="{{ route('dashboard.events.ticket-types.destroy', [$event, $ticketType]) }}"
                            formmethod="POST" name="_method" value="DELETE"
                            onclick="return confirm('Remove this ticket type?')">Remove</button>
                @endif
            </div>
        </form>
    </div>
</div>
