{{--
    Compact stacked event record for narrow screens — the mobile replacement for
    a row in the dashboard's Upcoming / Recently created tables.

    Expects:
      $event — an Event with ->confirmed_orders_count and ->sell_through
               attached (as the dashboard controller does).
--}}
@php
    $sold = (int) ($event->sell_through['sold'] ?? 0);
    $orders = (int) $event->confirmed_orders_count;
    if ($event->isCancelled()) {
        $statusLabel = 'Cancelled'; $statusMod = 'cancelled';
    } elseif ($event->isPublished()) {
        $statusLabel = 'Published'; $statusMod = 'live';
    } else {
        $statusLabel = 'Draft'; $statusMod = 'draft';
    }
@endphp
<div class="record-card">
    <div class="record-card__body">
        <a class="record-card__title cell-strong" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
        <span class="record-card__meta">
            {{ $event->starts_at ? $event->starts_at->format('j M Y · H:i') : 'Date not set' }}
        </span>
        <span class="record-card__meta">
            <span class="pill pill--{{ $statusMod }}">{{ $statusLabel }}</span>
        </span>
        <span class="record-card__meta record-card__meta--strong">
            {{ number_format($sold) }} {{ \Illuminate\Support\Str::plural('ticket', $sold) }}
            · {{ number_format($orders) }} {{ \Illuminate\Support\Str::plural('order', $orders) }}
        </span>
    </div>
    <a class="btn btn-outline btn-sm record-card__action" href="{{ route('dashboard.events.show', $event) }}">Open</a>
</div>
