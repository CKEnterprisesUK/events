{{--
    Sell-through cell for an event row.

    Expects $sellThrough = ['sold' => int, 'capacity' => int|null, 'percent' => float|null]
    (as produced by App\Models\Event::sellThrough()).

    - Unlimited / no resolvable capacity: a plain sold count with an em dash.
    - Otherwise: "sold / capacity" with a mini progress bar and percentage, and
      a "Sold out" / "Almost full" flag once the event is 90%+ committed.
--}}
@php
    $sold = (int) ($sellThrough['sold'] ?? 0);
    $capacity = $sellThrough['capacity'] ?? null;
    $percent = $sellThrough['percent'] ?? null;
    $isSoldOut = $percent !== null && $percent >= 100;
    $isAlmostFull = $percent !== null && $percent >= 90 && ! $isSoldOut;
@endphp

@if ($capacity === null)
    <span class="sell-through sell-through--unlimited" title="No fixed capacity">
        <span class="sell-through__count">{{ number_format($sold) }}</span>
        <span class="sell-through__cap">/ &infin;</span>
    </span>
@else
    <span class="sell-through {{ $isSoldOut ? 'is-sold-out' : ($isAlmostFull ? 'is-almost-full' : '') }}">
        <span class="sell-through__count">{{ number_format($sold) }} <span class="sell-through__cap">/ {{ number_format($capacity) }}</span></span>
        <span class="sell-through__bar" role="img"
              aria-label="{{ number_format($percent, 1) }} percent sold ({{ number_format($sold) }} of {{ number_format($capacity) }})">
            <span class="sell-through__fill" style="width: {{ min(100, $percent) }}%"></span>
        </span>
        @if ($isSoldOut)
            <span class="sell-through__flag">Sold out</span>
        @elseif ($isAlmostFull)
            <span class="sell-through__flag">Almost full</span>
        @endif
    </span>
@endif
