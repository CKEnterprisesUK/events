{{--
    Recent-scan recap, newest first. Fed from the session (per operator, per
    browser), capped to the last few scans — a convenience glance for the door,
    not an audit trail. Each row is colour-coded with the same door signal as
    the result banner: green = admitted, yellow = already scanned, red = not
    admitted.

    Expects: $history — array of { status, message, reference, customer,
             tickets, at } rows (may be empty).
--}}
@if (! empty($history))
    <div class="scan-history">
        <h2 class="scan-history__title">Recent scans</h2>
        <ul class="scan-history__list">
            @foreach ($history as $entry)
                @php
                    $signal = match ($entry['status']) {
                        'checked_in' => 'good',
                        'already_scanned' => 'warn',
                        default => 'bad',
                    };
                @endphp
                <li class="scan-history__item scan-history__item--{{ $signal }}">
                    <span class="scan-history__dot" aria-hidden="true"></span>
                    <span class="scan-history__body">
                        <span class="scan-history__message">{{ $entry['message'] }}</span>
                        @if (! empty($entry['reference']))
                            <span class="scan-history__meta">
                                {{ $entry['reference'] }}@if (! empty($entry['customer'])) &middot; {{ $entry['customer'] }}@endif
                                @if ($entry['status'] === 'checked_in' && $entry['tickets'] > 0)
                                    &middot; {{ $entry['tickets'] }} {{ $entry['tickets'] === 1 ? 'ticket' : 'tickets' }}
                                @endif
                            </span>
                        @endif
                    </span>
                    <span class="scan-history__time">{{ $entry['at'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <style>
        .scan-history { margin-top: 1.5rem; }
        .scan-history__title { font-size: 1rem; margin: 0 0 0.5rem; }
        .scan-history__list { list-style: none; margin: 0; padding: 0; }
        .scan-history__item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.6rem 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            margin-bottom: 0.4rem;
        }
        .scan-history__dot {
            flex: none;
            width: 0.65rem;
            height: 0.65rem;
            border-radius: 50%;
            background: #9ca3af;
        }
        .scan-history__item--good .scan-history__dot { background: #16a34a; }
        .scan-history__item--warn .scan-history__dot { background: #f59e0b; }
        .scan-history__item--bad  .scan-history__dot { background: #dc2626; }
        .scan-history__body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .scan-history__message { font-weight: 600; }
        .scan-history__meta { color: #6b7280; font-size: 0.85rem; }
        .scan-history__time { flex: none; color: #9ca3af; font-size: 0.85rem; font-variant-numeric: tabular-nums; }
    </style>
@endif
