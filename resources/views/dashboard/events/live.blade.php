{{--
    Full-screen "live event" dashboard, shown on a display at the door during an
    Event. (Live event dashboard)

    Deliberately a STANDALONE page (it does NOT extend layouts/dashboard) so it
    can own the whole black screen with no app chrome. Two phases, switched
    client-side off the polled feed:

      BEFORE doors open → a large centred countdown to the doors-opening time
        (the Event's start time) on black.
      AFTER doors open  → a 2:1 split: the left two-thirds carry the headline
        scan stats (total scanned, % of sold, total sold) above a per-operator
        bar graph; the right third is the live feed of scanned tickets, newest
        first, prepended as each check-in lands.

    A slim top bar offers "Return to platform"; the company/event logo sits
    bottom-right. The page never needs a manual refresh — it polls
    `dashboard.events.live.feed` on an interval and reconciles the DOM.

    Supplied by EventLiveController::show():
      $event    — the Event.
      $branding — EffectiveBranding (logo + primary colour).
      $doorsAt  — Carbon|null, the doors-opening target (Event start), or null.
      $snapshot — the embedded first-paint figures, same shape as the feed JSON.
--}}
@php
    use Illuminate\Support\Facades\Storage;

    $accent = $branding->hasPrimaryColour() ? $branding->primaryColour : '#2dd4bf';
    $logoUrl = $branding->hasLogo()
        ? Storage::disk('public')->url($branding->logoPath)
        : null;
    $feedUrl = route('dashboard.events.live.feed', $event);
    $platformUrl = route('dashboard.events.show', $event);
    // Doors target as an epoch (ms) for the client countdown; 0 = no target set.
    $doorsAtMs = $doorsAt !== null ? $doorsAt->getTimestampMs() : 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $event->name }} &middot; Live &middot; {{ config('app.name', 'Event Ticketing Platform') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: {{ $accent }};
            --bg: #000;
            --panel: #0b0b0d;
            --panel-2: #121216;
            --border: #1f1f25;
            --text: #f5f5f7;
            --text-dim: #8a8a94;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0; height: 100%;
            background: var(--bg); color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            overflow: hidden;
        }
        a { color: inherit; }

        .live {
            display: flex; flex-direction: column;
            height: 100vh; width: 100vw;
        }

        /* Top bar */
        .live-topbar {
            flex: 0 0 auto;
            display: flex; align-items: center; justify-content: space-between;
            padding: .75rem 1.25rem;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(255,255,255,.02), transparent);
        }
        .live-topbar .event-name {
            font-weight: 700; font-size: 1.05rem; letter-spacing: .01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 60vw;
        }
        .live-topbar .back {
            display: inline-flex; align-items: center; gap: .5rem;
            text-decoration: none; color: var(--text-dim);
            border: 1px solid var(--border); border-radius: 999px;
            padding: .4rem .9rem; font-size: .85rem; font-weight: 600;
            transition: color .15s, border-color .15s, background .15s;
        }
        .live-topbar .back:hover { color: var(--text); border-color: var(--accent); background: rgba(255,255,255,.03); }
        .live-topbar .back svg { width: 15px; height: 15px; }

        /* Body fills the space between top bar and bottom; relative so the logo
           can anchor bottom-right over whichever phase is showing. */
        .live-body { flex: 1 1 auto; position: relative; min-height: 0; }

        /* --- Countdown phase --- */
        .countdown {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 1.5rem; text-align: center; padding: 2rem;
        }
        .countdown .label { color: var(--text-dim); text-transform: uppercase; letter-spacing: .28em; font-size: .95rem; font-weight: 600; }
        .countdown .clock {
            display: flex; gap: 1.75rem; align-items: flex-start;
            font-variant-numeric: tabular-nums;
        }
        .countdown .unit { display: flex; flex-direction: column; align-items: center; gap: .5rem; }
        .countdown .unit .num {
            font-size: clamp(3.5rem, 13vw, 11rem); font-weight: 800; line-height: .9;
            color: var(--text);
        }
        .countdown .unit .cap { font-size: .8rem; letter-spacing: .22em; text-transform: uppercase; color: var(--text-dim); }
        .countdown .colon { font-size: clamp(2.5rem, 9vw, 7rem); font-weight: 300; color: var(--accent); line-height: 1.1; align-self: center; }
        .countdown .doors-at { color: var(--text-dim); font-size: 1rem; }
        .countdown .doors-open-msg { font-size: clamp(2rem, 6vw, 4rem); font-weight: 800; color: var(--accent); }

        /* --- Live phase: 2:1 split --- */
        .live-grid {
            position: absolute; inset: 0;
            display: grid; grid-template-columns: 2fr 1fr;
            gap: 1rem; padding: 1rem;
        }

        /* Left column: stats on top, operator graph below. */
        .live-left { display: flex; flex-direction: column; gap: 1rem; min-height: 0; }

        .stats {
            display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 1rem;
        }
        .stat {
            background: var(--panel); border: 1px solid var(--border); border-radius: 1rem;
            padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: .35rem;
        }
        .stat .stat-label { color: var(--text-dim); text-transform: uppercase; letter-spacing: .14em; font-size: .72rem; font-weight: 600; }
        .stat .stat-value { font-size: clamp(2.25rem, 5vw, 4.5rem); font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
        .stat .stat-sub { color: var(--text-dim); font-size: .85rem; }
        .stat--scanned .stat-value { color: var(--accent); }
        .stat--percent .stat-value { color: var(--text); }

        /* A thin progress bar under the % card showing scanned/sold. */
        .progress { height: 10px; border-radius: 999px; background: var(--panel-2); overflow: hidden; margin-top: .35rem; }
        .progress > span { display: block; height: 100%; width: 0; background: var(--accent); transition: width .6s ease; }

        .graph-card {
            flex: 1 1 auto; min-height: 0;
            background: var(--panel); border: 1px solid var(--border); border-radius: 1rem;
            padding: 1.25rem 1.5rem; display: flex; flex-direction: column;
        }
        .graph-card h2 { margin: 0 0 1rem; font-size: .9rem; text-transform: uppercase; letter-spacing: .14em; color: var(--text-dim); font-weight: 600; }
        .bars { flex: 1 1 auto; overflow-y: auto; display: flex; flex-direction: column; gap: .85rem; padding-right: .25rem; }
        .bar-row { display: grid; grid-template-columns: minmax(90px, 22%) 1fr auto; align-items: center; gap: .85rem; }
        .bar-row .bar-name { font-weight: 600; font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-row .bar-track { height: 18px; border-radius: 999px; background: var(--panel-2); overflow: hidden; }
        .bar-row .bar-fill { height: 100%; background: var(--accent); border-radius: 999px; min-width: 2px; transition: width .6s ease; }
        .bar-row .bar-val { font-variant-numeric: tabular-nums; font-weight: 700; font-size: 1rem; min-width: 2.5rem; text-align: right; }
        .graph-empty, .feed-empty { color: var(--text-dim); font-size: .95rem; margin: auto 0; text-align: center; }

        /* Right column: live feed. */
        .feed-card {
            background: var(--panel); border: 1px solid var(--border); border-radius: 1rem;
            padding: 1.25rem 1.25rem .5rem; display: flex; flex-direction: column; min-height: 0;
        }
        .feed-card h2 {
            margin: 0 0 .9rem; font-size: .9rem; text-transform: uppercase; letter-spacing: .14em; color: var(--text-dim); font-weight: 600;
            display: flex; align-items: center; gap: .55rem;
        }
        .feed-card h2 .dot { width: 9px; height: 9px; border-radius: 999px; background: var(--accent); box-shadow: 0 0 0 0 var(--accent); animation: pulse 1.8s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--accent) 60%, transparent); } 70% { box-shadow: 0 0 0 10px transparent; } 100% { box-shadow: 0 0 0 0 transparent; } }
        .feed { flex: 1 1 auto; overflow-y: auto; display: flex; flex-direction: column; gap: .6rem; padding-bottom: .75rem; }
        .feed-item {
            background: var(--panel-2); border: 1px solid var(--border); border-left: 3px solid var(--accent);
            border-radius: .7rem; padding: .7rem .85rem;
            display: flex; flex-direction: column; gap: .2rem;
            animation: slidein .4s ease;
        }
        @keyframes slidein { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
        .feed-item .fi-top { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; }
        .feed-item .fi-name { font-weight: 700; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .feed-item .fi-time { color: var(--text-dim); font-size: .78rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .feed-item .fi-meta { color: var(--text-dim); font-size: .82rem; display: flex; gap: .5rem; flex-wrap: wrap; }
        .feed-item .fi-tickets { color: var(--accent); font-weight: 600; }

        /* Logo, anchored bottom-right over the body. */
        .live-logo {
            position: absolute; bottom: 1.25rem; right: 1.5rem; z-index: 5;
            max-height: 56px; max-width: 220px; opacity: .9;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,.6));
            pointer-events: none;
        }
        .live-grid + .live-logo { bottom: .5rem; }

        [hidden] { display: none !important; }

        @media (max-width: 900px) {
            .live-grid { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
            .stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="live"
     data-feed-url="{{ $feedUrl }}"
     data-doors-at="{{ $doorsAtMs }}"
     data-doors-open="{{ $snapshot['doorsOpen'] ? '1' : '0' }}">

    <div class="live-topbar">
        <span class="event-name">{{ $event->name }}</span>
        <a class="back" href="{{ $platformUrl }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Return to platform
        </a>
    </div>

    <div class="live-body">
        {{-- Countdown phase --}}
        <div class="countdown" id="countdown" @if($snapshot['doorsOpen']) hidden @endif>
            <div class="label">Doors open in</div>
            <div class="clock" id="clock">
                <div class="unit"><span class="num" data-cd="days">00</span><span class="cap">Days</span></div>
                <span class="colon">:</span>
                <div class="unit"><span class="num" data-cd="hours">00</span><span class="cap">Hours</span></div>
                <span class="colon">:</span>
                <div class="unit"><span class="num" data-cd="mins">00</span><span class="cap">Mins</span></div>
                <span class="colon">:</span>
                <div class="unit"><span class="num" data-cd="secs">00</span><span class="cap">Secs</span></div>
            </div>
            @if ($doorsAt !== null)
                <div class="doors-at">{{ $doorsAt->format('l j F Y, H:i') }}</div>
            @else
                <div class="doors-at">Doors time not set</div>
            @endif
            <div class="doors-open-msg" id="doorsOpenMsg" hidden>Doors are open</div>
        </div>

        {{-- Live phase: 2:1 split --}}
        <div class="live-grid" id="liveGrid" @unless($snapshot['doorsOpen']) hidden @endunless>
            <div class="live-left">
                <div class="stats">
                    <div class="stat stat--scanned">
                        <span class="stat-label">Tickets scanned</span>
                        <span class="stat-value" id="statScanned">{{ number_format($snapshot['stats']['scanned']) }}</span>
                        <span class="stat-sub"><span id="statOrders">{{ number_format($snapshot['stats']['checkedInOrders']) }}</span> orders checked in</span>
                    </div>
                    <div class="stat stat--percent">
                        <span class="stat-label">Of tickets sold</span>
                        <span class="stat-value" id="statPercent">{{ $snapshot['stats']['percent'] === null ? '—' : $snapshot['stats']['percent'].'%' }}</span>
                        <div class="progress"><span id="statProgress" style="width: {{ $snapshot['stats']['percent'] ?? 0 }}%"></span></div>
                    </div>
                    <div class="stat stat--sold">
                        <span class="stat-label">Total sold</span>
                        <span class="stat-value" id="statSold">{{ number_format($snapshot['stats']['sold']) }}</span>
                        <span class="stat-sub">tickets</span>
                    </div>
                </div>

                <div class="graph-card">
                    <h2>Scanned by operator</h2>
                    <div class="bars" id="operatorBars"></div>
                    <div class="graph-empty" id="operatorEmpty" hidden>No check-ins yet</div>
                </div>
            </div>

            <div class="feed-card">
                <h2><span class="dot"></span> Live scan feed</h2>
                <div class="feed" id="feed"></div>
                <div class="feed-empty" id="feedEmpty" hidden>Waiting for the first scan…</div>
            </div>
        </div>

        @if ($logoUrl)
            <img class="live-logo" src="{{ $logoUrl }}" alt="{{ $event->name }}">
        @endif
    </div>
</div>

<script>
(function () {
    var root = document.querySelector('.live');
    var FEED_URL = root.dataset.feedUrl;
    var doorsAt = parseInt(root.dataset.doorsAt, 10) || 0;      // epoch ms, 0 = none
    var doorsOpen = root.dataset.doorsOpen === '1';
    var POLL_MS = 4000;

    var elCountdown = document.getElementById('countdown');
    var elGrid = document.getElementById('liveGrid');
    var elDoorsOpenMsg = document.getElementById('doorsOpenMsg');
    var elClock = document.getElementById('clock');
    var cd = {
        days: elCountdown.querySelector('[data-cd="days"]'),
        hours: elCountdown.querySelector('[data-cd="hours"]'),
        mins: elCountdown.querySelector('[data-cd="mins"]'),
        secs: elCountdown.querySelector('[data-cd="secs"]')
    };

    var elScanned = document.getElementById('statScanned');
    var elOrders = document.getElementById('statOrders');
    var elPercent = document.getElementById('statPercent');
    var elProgress = document.getElementById('statProgress');
    var elSold = document.getElementById('statSold');
    var elBars = document.getElementById('operatorBars');
    var elBarsEmpty = document.getElementById('operatorEmpty');
    var elFeed = document.getElementById('feed');
    var elFeedEmpty = document.getElementById('feedEmpty');

    // Track the newest scan we've rendered so we only animate truly new rows.
    var lastTopRef = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function nf(n) { return (n || 0).toLocaleString(); }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    // ---- Countdown ----
    function showPhase(open) {
        doorsOpen = open;
        elCountdown.hidden = open;
        elGrid.hidden = !open;
    }

    function tickCountdown() {
        if (doorsOpen) return;
        if (!doorsAt) { return; }            // no target: stay on countdown shell
        var diff = doorsAt - Date.now();
        if (diff <= 0) {
            cd.days.textContent = '00'; cd.hours.textContent = '00';
            cd.mins.textContent = '00'; cd.secs.textContent = '00';
            elClock.hidden = true;
            elDoorsOpenMsg.hidden = false;
            // The feed poll flips us to the live phase; this is the visual cue
            // in the gap before the next poll lands.
            return;
        }
        var secs = Math.floor(diff / 1000);
        var days = Math.floor(secs / 86400); secs -= days * 86400;
        var hours = Math.floor(secs / 3600); secs -= hours * 3600;
        var mins = Math.floor(secs / 60); secs -= mins * 60;
        cd.days.textContent = pad(days);
        cd.hours.textContent = pad(hours);
        cd.mins.textContent = pad(mins);
        cd.secs.textContent = pad(secs);
    }

    // ---- Render feed figures ----
    function renderStats(stats) {
        elScanned.textContent = nf(stats.scanned);
        elOrders.textContent = nf(stats.checkedInOrders);
        elSold.textContent = nf(stats.sold);
        elPercent.textContent = stats.percent == null ? '—' : stats.percent + '%';
        elProgress.style.width = (stats.percent == null ? 0 : Math.min(100, stats.percent)) + '%';
    }

    function renderOperators(rows) {
        if (!rows || !rows.length) {
            elBars.innerHTML = '';
            elBarsEmpty.hidden = false;
            return;
        }
        elBarsEmpty.hidden = true;
        var max = rows.reduce(function (m, r) { return Math.max(m, r.tickets || 0); }, 0) || 1;
        var html = rows.map(function (r) {
            var pct = Math.round(((r.tickets || 0) / max) * 100);
            return '<div class="bar-row">'
                + '<span class="bar-name" title="' + esc(r.name) + '">' + esc(r.name) + '</span>'
                + '<span class="bar-track"><span class="bar-fill" style="width:' + pct + '%"></span></span>'
                + '<span class="bar-val">' + nf(r.tickets) + '</span>'
                + '</div>';
        }).join('');
        elBars.innerHTML = html;
    }

    function feedItemHtml(s) {
        var meta = [];
        if (s.tickets) meta.push('<span class="fi-tickets">' + nf(s.tickets) + (s.tickets === 1 ? ' ticket' : ' tickets') + '</span>');
        if (s.breakdown) meta.push('<span>' + esc(s.breakdown) + '</span>');
        meta.push('<span>' + esc(s.operator) + '</span>');
        return '<div class="feed-item" data-ref="' + esc(s.reference) + '">'
            + '<div class="fi-top"><span class="fi-name">' + esc(s.name) + '</span>'
            + '<span class="fi-time">' + esc(s.atLabel) + '</span></div>'
            + '<div class="fi-meta">' + meta.join('') + '</div>'
            + '</div>';
    }

    function renderFeed(recent) {
        if (!recent || !recent.length) {
            if (!elFeed.children.length) { elFeedEmpty.hidden = false; }
            return;
        }
        elFeedEmpty.hidden = true;

        // Rebuild the feed in server order (newest first). Cheap at <=30 rows
        // and keeps the list exactly in sync with the source of truth. New top
        // rows animate in via the CSS keyframe on fresh nodes.
        var topRef = recent[0].reference;
        if (topRef === lastTopRef && elFeed.children.length) {
            return; // nothing newer since last poll
        }
        lastTopRef = topRef;
        elFeed.innerHTML = recent.map(feedItemHtml).join('');
    }

    function apply(data) {
        if (data.doorsOpen && !doorsOpen) { showPhase(true); }
        if (!data.doorsOpen && doorsOpen) { showPhase(false); }
        if (data.doorsOpen) {
            renderStats(data.stats);
            renderOperators(data.byOperator);
            renderFeed(data.recent);
        }
    }

    function poll() {
        fetch(FEED_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data) apply(data); })
            .catch(function () { /* transient network error: keep the last good view, retry next tick */ });
    }

    // Embedded first-paint snapshot already rendered server-side; prime the
    // feed figures + start the loops.
    @if ($snapshot['doorsOpen'])
        renderStats(@json($snapshot['stats']));
        renderOperators(@json($snapshot['byOperator']));
        renderFeed(@json($snapshot['recent']));
    @endif

    setInterval(tickCountdown, 1000);
    tickCountdown();
    setInterval(poll, POLL_MS);
    poll();
})();
</script>
</body>
</html>
