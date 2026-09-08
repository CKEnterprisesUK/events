@php
    // Standalone, iframe-friendly page. Not the dashboard/storefront layout so
    // it embeds cleanly on a third-party site. Every outbound link opens in the
    // TOP window / a new tab (target="_blank") so the buyer leaves the iframe.
    $brand = $company->primary_colour ?: '#4f46e5';
    $currency = $company->currency ?? 'GBP';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $company->name }} — Book tickets</title>
    <style>
        :root { --brand: {{ $brand }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #111827; background: #fff; padding: 12px;
        }
        .widget-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
        .widget-head h1 { font-size: 1rem; margin: 0; }
        .widget-head a { font-size: 0.8rem; color: var(--brand); text-decoration: none; }
        .feed { display: flex; flex-direction: column; gap: 10px; }
        .item {
            display: flex; gap: 12px; align-items: center; text-decoration: none; color: inherit;
            border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; transition: border-color 0.15s;
        }
        .item:hover { border-color: var(--brand); }
        .item__thumb { flex: none; width: 56px; height: 56px; border-radius: 8px; overflow: hidden; background: #f3f4f6; }
        .item__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .item__body { min-width: 0; flex: 1; }
        .item__name { font-weight: 600; font-size: 0.92rem; margin: 0 0 2px; }
        .item__meta { font-size: 0.8rem; color: #6b7280; margin: 0; }
        .item__cta { flex: none; font-size: 0.82rem; font-weight: 600; color: #fff; background: var(--brand); border-radius: 999px; padding: 6px 12px; }
        .empty { color: #6b7280; font-size: 0.9rem; text-align: center; padding: 24px 8px; }
        .powered { margin-top: 12px; font-size: 0.72rem; color: #9ca3af; text-align: center; }
    </style>
</head>
{{-- base target ensures every link (and the JS fallback) opens in a new tab
     even when the widget is embedded inside an iframe. --}}
<base target="_blank">
<body>
    <div class="widget-head">
        <h1>{{ $company->name }}</h1>
        <a href="{{ $storefrontUrl }}" target="_blank" rel="noopener">See all events →</a>
    </div>

    @if ($events->isEmpty())
        <p class="empty">No events on sale right now. Check back soon.</p>
    @else
        <div class="feed">
            @foreach ($events as $event)
                @php
                    $poster = $event['poster_path'] ?? null;
                    $startsAt = !empty($event['starts_at']) ? \Illuminate\Support\Carbon::parse($event['starts_at']) : null;
                    $eventUrl = url($company->slug . '/' . $event['id']);
                @endphp
                <a class="item" href="{{ $eventUrl }}" target="_blank" rel="noopener">
                    <span class="item__thumb">
                        @if ($poster)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($poster) }}" alt="" loading="lazy">
                        @endif
                    </span>
                    <span class="item__body">
                        <span class="item__name">{{ $event['name'] }}</span>
                        <span class="item__meta">
                            @if ($startsAt){{ $startsAt->format('D, j M Y · H:i') }}@endif
                            @if (!empty($event['venue'])) · {{ $event['venue'] }}@endif
                        </span>
                    </span>
                    <span class="item__cta">Book</span>
                </a>
            @endforeach
        </div>
    @endif

    <p class="powered">Powered by Events by CK Enterprises</p>
</body>
</html>
