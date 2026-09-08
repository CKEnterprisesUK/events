@php
    // Standalone, iframe-friendly per-event tickets widget. "Get tickets" opens
    // the public event page (which drives checkout) in a new tab.
    $brand = $company->primary_colour ?: '#4f46e5';
    $currency = $company->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
    $startsAt = $event->starts_at;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $event->name }} — Tickets</title>
    <style>
        :root { --brand: {{ $brand }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #111827; background: #fff; padding: 14px;
        }
        .event-head { margin-bottom: 12px; }
        .event-head h1 { font-size: 1.05rem; margin: 0 0 3px; }
        .event-head p { font-size: 0.82rem; color: #6b7280; margin: 0; }
        .types { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
        .type {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px;
        }
        .type__name { font-weight: 600; font-size: 0.9rem; margin: 0; }
        .type__desc { font-size: 0.78rem; color: #6b7280; margin: 2px 0 0; }
        .type__price { font-weight: 700; font-size: 0.92rem; white-space: nowrap; }
        .type__price--free { color: #047857; }
        .cta {
            display: block; text-align: center; text-decoration: none; font-weight: 600; font-size: 0.95rem;
            color: #fff; background: var(--brand); border-radius: 10px; padding: 12px 16px;
        }
        .empty { color: #6b7280; font-size: 0.9rem; text-align: center; padding: 20px 8px; }
        .powered { margin-top: 12px; font-size: 0.72rem; color: #9ca3af; text-align: center; }
    </style>
</head>
<base target="_blank">
<body>
    <div class="event-head">
        <h1>{{ $event->name }}</h1>
        <p>
            @if ($startsAt){{ $startsAt->format('D, j M Y · H:i') }}@endif
            @if ($event->venue) · {{ $event->venue }}@endif
        </p>
    </div>

    @if ($ticketTypes->isEmpty())
        <p class="empty">Tickets aren't on sale right now.</p>
    @else
        <div class="types">
            @foreach ($ticketTypes as $type)
                <div class="type">
                    <div>
                        <p class="type__name">{{ $type->name }}</p>
                        @if ($type->description)
                            <p class="type__desc">{{ $type->description }}</p>
                        @endif
                    </div>
                    @if ($type->isFree())
                        <span class="type__price type__price--free">Free</span>
                    @else
                        <span class="type__price">{{ $money($type->price_minor) }}</span>
                    @endif
                </div>
            @endforeach
        </div>
        <a class="cta" href="{{ $eventUrl }}" target="_blank" rel="noopener">Get tickets</a>
    @endif

    <p class="powered">Powered by Events by CK Enterprises</p>
</body>
</html>
