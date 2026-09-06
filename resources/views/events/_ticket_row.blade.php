@php
    /** @var array $type */
    /** @var callable $money */
    $selectable = $selectable ?? false;
    $index = $index ?? 0;
    $qtyId = 'qty-' . $type['id'];
    $max = min(20, max(0, (int) $type['available']));
@endphp

<li class="ticket-type" data-sale-state="{{ $type['sale_state'] }}" @if ($type['sold_out']) data-sold-out @endif>
    <div class="ticket-type__info">
        <span class="ticket-type-name">{{ $type['name'] }}</span>
        <span class="ticket-type-price">
            @if ($type['is_free'])
                Free
            @else
                {{ $money($type['price_minor']) }}
            @endif
        </span>
        <span class="ticket-type-availability">
            @if ($type['sold_out'])
                Sold out
            @elseif ($type['sale_state'] === 'not_yet')
                Not yet on sale
            @elseif ($type['sale_state'] === 'ended')
                Sale ended
            @else
                {{ number_format($type['available']) }} remaining
            @endif
        </span>
    </div>

    <div class="ticket-type__action">
        @if ($selectable)
            <input type="hidden" name="items[{{ $index }}][ticket_type_id]" value="{{ $type['id'] }}">
            <div class="qty-stepper" role="group" aria-label="Quantity for {{ $type['name'] }}">
                <button type="button" class="qty-btn" data-step="-1" data-target="{{ $qtyId }}" aria-label="Decrease quantity">&minus;</button>
                <input class="qty-input" type="number" inputmode="numeric"
                       id="{{ $qtyId }}"
                       name="items[{{ $index }}][quantity]"
                       value="{{ (int) old('items.' . $index . '.quantity', 0) }}"
                       min="0" max="{{ $max }}" step="1"
                       data-qty-input data-price="{{ $type['is_free'] ? 0 : $type['price_minor'] }}">
                <button type="button" class="qty-btn" data-step="1" data-target="{{ $qtyId }}" aria-label="Increase quantity">+</button>
            </div>
        @else
            <span class="ticket-type-state-pill">
                @switch($type['sale_state'])
                    @case('on_sale') On sale @break
                    @case('not_yet') Coming soon @break
                    @case('ended') Ended @break
                    @default Unavailable
                @endswitch
            </span>
        @endif
    </div>
</li>
