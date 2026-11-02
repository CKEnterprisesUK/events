{{--
    The check-in result banner for a single scan. Rendered both inline on a
    full-page POST (no-JS fallback) and as the body of the dismissible overlay
    the live scanner shows over the running camera (the JS path). Keeping it in
    one partial means both paths read identically.

    Expects: $result — the structured outcome from ScanController::resolve().
--}}
@php
    // Map every outcome onto one of three door signals so the whole banner
    // reads as a single colour the operator can act on at a glance: green =
    // let them in, yellow = already scanned, red = do not admit.
    $signal = match ($result['status']) {
        'checked_in' => 'good',
        'already_scanned' => 'warn',
        default => 'bad',
    };

    // Total tickets on the order, summed from the type breakdown so the door
    // can confirm how many people this one code admits.
    $ticketCount = collect($result['breakdown'] ?? [])->sum('quantity');
@endphp

<div class="scan-result scan-result--{{ $signal }}{{ ! empty($result['just_scanned']) ? ' scan-result--just-scanned' : '' }}" role="status" aria-live="polite">
    <p class="scan-result__message">{{ $result['message'] }}</p>

    @if (! empty($result['just_scanned']))
        {{-- Accidental immediate re-scan of the ticket just scanned: a gentle
             nudge so the operator knows it is the same one, not a new entry. --}}
        <p class="scan-result__count" style="font-size: 1rem; font-weight: 600;">
            This is the ticket you just scanned — no need to scan it again.
        </p>
    @endif

    @if ($result['status'] === 'checked_in')
        {{-- Headline: how many tickets this code admits. --}}
        <p class="scan-result__count">
            <span class="scan-result__count-num">{{ $ticketCount }}</span>
            {{ $ticketCount === 1 ? 'ticket' : 'tickets' }}
        </p>

        @isset($result['order'])
            <p class="scan-result__ref">
                Order {{ $result['order']->order_reference }}
                &middot; {{ $result['order']->customer_name }}
            </p>
        @endisset

        @if (! empty($result['breakdown']))
            {{-- Per-level breakdown: which ticket type(s) and how many of
                 each, so the door knows the access level. --}}
            <ul class="scan-result__levels">
                @foreach ($result['breakdown'] as $line)
                    <li class="scan-result__level">
                        <span class="scan-result__level-name">{{ $line['name'] }}</span>
                        <span class="scan-result__level-qty">&times;{{ $line['quantity'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="scan-result__meta">
            Checked in at {{ $result['scanned_at']->format('Y-m-d H:i:s') }}.
        </p>
    @endif

    @if ($result['status'] === 'already_scanned' && isset($result['scanned_at']))
        {{-- Who checked it in and how long ago, so a second operator sees this
             was a prior check-in — and by whom — not their own scan. The name
             is omitted when the scanning operator's account no longer exists. --}}
        <p class="scan-result__meta">
            Previously scanned
            @if (! empty($result['scanned_by_name']))
                by {{ $result['scanned_by_name'] }}
            @endif
            {{ $result['scanned_at']->diffForHumans() }}
            ({{ $result['scanned_at']->format('Y-m-d H:i:s') }}).
        </p>
    @endif
</div>
