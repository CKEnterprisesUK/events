{{--
    Full-screen "live event" dashboard, shown on a display at the door during an
    Event. (Live event dashboard)

    Deliberately a STANDALONE page (it does NOT extend layouts/dashboard) so it
    owns the whole black screen with no app chrome. Two phases, switched
    client-side off the polled feed:

      BEFORE doors open → a large, clear countdown to the doors-opening time
        (the Event's start time).
      AFTER doors open  → a 2:1 split: the left two-thirds lead with one very
        large "scanned" figure plus % of sold, total sold and issues, above a
        per-operator bar chart (ordered top-scanner-first, by ORDERS scanned);
        the right third is the live feed of every scan — successes, duplicates
        and errors — newest first.

    Clean sans-serif throughout, numbers on black. A slim top bar offers
    "Return to platform"; the company/event logo (or the Events by CK
    Enterprises wordmark) sits bottom-right. The page polls
    `dashboard.events.live.feed` and reconciles the DOM, so it stays live
    without a manual refresh.

    Supplied by EventLiveController::show():
      $event    — the Event.
      $branding — EffectiveBranding (logo + primary colour).
      $doorsAt  — Carbon|null, the doors-opening target (Event start), or null.
      $snapshot — the embedded first-paint figures, same shape as the feed JSON.
--}}
@php
    use Illuminate\Support\Facades\Storage;

    $accent = $branding->hasPrimaryColour() ? $branding->primaryColour : '#3b82f6';
    $logoUrl = $branding->hasLogo()
        ? Storage::disk('public')->url($branding->logoPath)
        : null;
    $feedUrl = route('dashboard.events.live.feed', $event);
    $platformUrl = route('dashboard.events.show', $event);
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
    {{-- One clean sans for the whole screen. Inter is the platform's UI face;
         Cabin Sketch is only for the "Events by CK Enterprises" wordmark. --}}
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: {{ $accent }};
            --bg: #0a0a0b;
            --ink: #f5f5f7;
            --ink-dim: #86868b;
            --hair: rgba(255,255,255,.1);
            --ok: #34c759;
            --dup: #ffb02e;
            --err: #ff453a;
            --sans: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            /* Operator bar palette, cycled in order so the top scanner gets the
               first (accent) colour. */
            --c1: var(--accent);
            --c2: #34c759;
            --c3: #ffb02e;
            --c4: #af52de;
            --c5: #ff6482;
            --c6: #64d2ff;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; height: 100%; background: var(--bg); color: var(--ink);
            font-family: var(--sans); overflow: hidden;
            -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
        }

        .live { display: flex; flex-direction: column; height: 100vh; width: 100vw; }

        /* ---- Top bar ---- */
        .topbar {
            flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between;
            padding: .9rem 1.75rem; border-bottom: 1px solid var(--hair);
        }
        .topbar .mark { display: flex; align-items: center; gap: .85rem; min-width: 0; }
        .topbar .live-tag {
            font-size: .7rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
            color: #fff; background: var(--err); padding: .22rem .5rem; border-radius: 4px; white-space: nowrap;
        }
        .topbar .event-name {
            font-weight: 600; font-size: 1.1rem; letter-spacing: -.01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .topbar .back {
            display: inline-flex; align-items: center; gap: .5rem; text-decoration: none;
            color: var(--ink-dim); font-size: .85rem; font-weight: 600; letter-spacing: .01em;
            white-space: nowrap; transition: color .15s;
        }
        .topbar .back:hover { color: var(--ink); }
        .topbar .right { display: flex; align-items: center; gap: 1.5rem; }

        /* Connection status: green while fresh, amber while reconnecting, red
           when signed out — so a frozen wall display is never mistaken for a
           quiet door. */
        .conn {
            display: inline-flex; align-items: center; gap: .5rem;
            font-size: .85rem; font-weight: 600; color: var(--ink-dim);
            white-space: nowrap; font-variant-numeric: tabular-nums;
            background: none; border: 0; padding: 0; font-family: inherit; cursor: default;
        }
        .conn .dot { width: .6rem; height: .6rem; border-radius: 50%; background: var(--ok); flex: 0 0 auto; }
        .conn--stale { color: var(--dup); }
        .conn--stale .dot { background: var(--dup); animation: conn-pulse 1s ease-in-out infinite; }
        .conn--down { color: #fff; background: var(--err); padding: .3rem .7rem; border-radius: 6px; cursor: pointer; }
        .conn--down .dot { background: #fff; }
        @keyframes conn-pulse { 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .conn--stale .dot { animation: none; } }
        .topbar .back svg { width: 16px; height: 16px; }

        .body { flex: 1 1 auto; position: relative; min-height: 0; }

        /* ---- Countdown phase ---- */
        .countdown {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 2rem; padding: 1.5rem;
        }
        .countdown .eyebrow {
            font-size: 1rem; font-weight: 600; letter-spacing: .35em; text-transform: uppercase;
            color: var(--ink-dim); padding-left: .35em;
        }
        /* Never overflow: four units + three separators share the width. */
        .clock { display: flex; align-items: baseline; gap: min(3vw, 2.5rem); max-width: 100vw; }
        .clock .unit { display: flex; flex-direction: column; align-items: center; gap: .9rem; }
        .clock .num {
            font-weight: 800; font-variant-numeric: tabular-nums;
            font-size: min(15vw, 12rem); line-height: .85; letter-spacing: -.03em; color: var(--ink);
        }
        .clock .cap {
            font-size: min(1.6vw, .9rem); font-weight: 600; letter-spacing: .28em; text-transform: uppercase;
            color: var(--ink-dim);
        }
        .clock .sep {
            align-self: center; font-weight: 300; color: var(--accent);
            font-size: min(10vw, 8rem); line-height: .85;
        }
        .countdown .when { font-size: 1.3rem; color: var(--ink-dim); font-weight: 500; }
        .countdown .open-now {
            font-size: min(8vw, 5.5rem); font-weight: 800; letter-spacing: -.02em;
            color: var(--accent); text-transform: uppercase;
        }

        /* ---- Live phase: 2:1 split ---- */
        .grid { position: absolute; inset: 0; display: grid; grid-template-columns: 2fr 1fr; }
        .left {
            display: flex; flex-direction: column; min-height: 0;
            padding: clamp(1.5rem, 3vw, 3rem); border-right: 1px solid var(--hair);
        }

        /* Headline: the big scanned number dominates. */
        .headline { display: flex; align-items: flex-start; gap: clamp(2rem, 5vw, 5rem); flex-wrap: wrap; }
        .scanned { display: flex; flex-direction: column; min-width: 0; }
        .scanned .k {
            font-size: clamp(.9rem, 1.2vw, 1.15rem); font-weight: 600; letter-spacing: .2em;
            text-transform: uppercase; color: var(--ink-dim); margin-bottom: .2rem;
        }
        .scanned .n {
            font-weight: 900; font-variant-numeric: tabular-nums;
            font-size: min(26vw, 22rem); line-height: .82; letter-spacing: -.045em; color: var(--ink);
        }
        .scanned .sub { margin-top: .5rem; color: var(--ink-dim); font-size: clamp(1rem, 1.3vw, 1.3rem); font-weight: 500; }
        .scanned .sub b { color: var(--ink); font-weight: 700; }

        /* Secondary figures stacked beside the big number. */
        .side { display: flex; flex-direction: column; gap: clamp(1.25rem, 2.5vw, 2.25rem); padding-top: .3rem; }
        .figure .k {
            font-size: .8rem; font-weight: 600; letter-spacing: .16em; text-transform: uppercase;
            color: var(--ink-dim); margin-bottom: .15rem;
        }
        .figure .v { font-weight: 800; font-variant-numeric: tabular-nums; font-size: clamp(2.25rem, 4.5vw, 4rem); line-height: 1; letter-spacing: -.02em; }
        .figure--pct .v { color: var(--accent); }
        .figure .bar { height: 7px; width: clamp(150px, 16vw, 260px); background: rgba(255,255,255,.1); margin-top: .6rem; border-radius: 4px; overflow: hidden; }
        .figure .bar > i { display: block; height: 100%; background: var(--accent); width: 0; border-radius: 4px; transition: width .7s cubic-bezier(.2,.7,.2,1); }

        /* Operator bar chart fills the rest of the left column. */
        .graph { margin-top: auto; padding-top: clamp(1.25rem, 2.5vw, 2rem); display: flex; flex-direction: column; min-height: 0; }
        .graph .h {
            font-size: .8rem; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: var(--ink-dim);
            margin-bottom: 1rem; border-top: 1px solid var(--hair); padding-top: 1rem;
        }
        .bars { display: flex; flex-direction: column; gap: .8rem; overflow-y: auto; }
        .bar-row { display: grid; grid-template-columns: minmax(120px, 22%) 1fr auto; align-items: center; gap: 1rem; }
        .bar-row .nm { font-size: 1.1rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-row .trk { height: 26px; background: rgba(255,255,255,.06); border-radius: 6px; overflow: hidden; }
        .bar-row .fil { height: 100%; min-width: 4px; border-radius: 6px; transition: width .7s cubic-bezier(.2,.7,.2,1); }
        .bar-row .vl { font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums; min-width: 2.75rem; text-align: right; letter-spacing: -.02em; }
        .empty { color: var(--ink-dim); font-size: 1.05rem; padding: 1rem 0; }

        /* ---- Right column: live feed ---- */
        .feed-col { display: flex; flex-direction: column; min-height: 0; padding: clamp(1.5rem, 2vw, 2rem) clamp(1rem, 1.5vw, 1.5rem) 0; }
        .feed-col .h {
            display: flex; align-items: center; gap: .55rem; font-size: .8rem; font-weight: 600; letter-spacing: .16em;
            text-transform: uppercase; color: var(--ink-dim); margin-bottom: 1rem;
        }
        .feed-col .h .beat { width: 9px; height: 9px; background: var(--err); border-radius: 50%; animation: beat 1.5s ease-in-out infinite; }
        @keyframes beat { 0%,100% { opacity: .3; } 50% { opacity: 1; } }
        .feed { flex: 1 1 auto; overflow-y: auto; display: flex; flex-direction: column; padding-bottom: 5rem; }

        .row {
            display: grid; grid-template-columns: 4px 1fr auto; gap: .9rem; align-items: start;
            padding: .85rem .25rem; border-bottom: 1px solid var(--hair); animation: rowin .35s ease;
        }
        @keyframes rowin { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        .row .tick { align-self: stretch; border-radius: 2px; background: var(--ink-dim); }
        .row.is-success .tick { background: var(--ok); }
        .row.is-duplicate .tick { background: var(--dup); }
        .row.is-error .tick { background: var(--err); }
        .row .main { min-width: 0; }
        .row .name { font-size: 1.1rem; font-weight: 700; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row .meta { color: var(--ink-dim); font-size: .9rem; margin-top: .15rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row .flag { font-size: .78rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; margin-top: .25rem; display: inline-block; }
        .row.is-duplicate .flag { color: var(--dup); }
        .row.is-error .flag { color: var(--err); }
        .row .aside { display: flex; flex-direction: column; align-items: flex-end; gap: .15rem; }
        .row .at { font-size: .85rem; color: var(--ink-dim); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .row .count { font-size: 1.2rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
        .row.is-success .count { color: var(--ok); }
        .row .count--none { color: var(--ink-dim); }

        .logo {
            position: absolute; bottom: 1.25rem; right: 1.5rem; z-index: 5;
            max-height: 52px; max-width: 200px; opacity: .9; pointer-events: none;
            filter: drop-shadow(0 2px 10px rgba(0,0,0,.6));
        }

        /* "Events by CK Enterprises" wordmark fallback, bottom-right. */
        .wordmark {
            position: absolute; bottom: 1.4rem; right: 1.75rem; z-index: 5;
            display: inline-flex; align-items: baseline; gap: .4rem;
            font-family: 'Cabin Sketch', cursive; font-weight: 700; line-height: 1;
            opacity: .9; pointer-events: none; user-select: none;
        }
        .wordmark .events { font-size: 1.6rem; color: #2dd4bf; }
        .wordmark .by {
            font-family: var(--sans); font-weight: 500; font-size: .72rem; letter-spacing: .06em;
            text-transform: uppercase; color: var(--ink-dim);
        }
        .wordmark .ck { font-size: 1.6rem; color: var(--ink); }

        [hidden] { display: none !important; }

        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
            .left { border-right: none; border-bottom: 1px solid var(--hair); }
        }
        @media (max-width: 600px) {
            .topbar { flex-wrap: wrap; gap: .5rem; padding: .7rem 1rem; }
            .topbar .right { gap: 1rem; }
        }
    </style>
</head>
<body>
<div class="live"
     data-feed-url="{{ $feedUrl }}"
     data-doors-at="{{ $doorsAtMs }}"
     data-doors-open="{{ $snapshot['doorsOpen'] ? '1' : '0' }}">

    <header class="topbar">
        <div class="mark">
            <span class="live-tag">Live</span>
            <span class="event-name">{{ $event->name }}</span>
        </div>
        <div class="right">
            {{-- Connection status, updated by poll(). role=status so a screen
                 reader announces state changes (not the per-second counter). --}}
            <button type="button" class="conn" id="conn" disabled>
                <span class="dot" aria-hidden="true"></span>
                <span id="conn-text">Connecting…</span>
            </button>
            <span class="visually-hidden" id="conn-announce" role="status" aria-live="polite"
                  style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap"></span>
            <a class="back" href="{{ $platformUrl }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Return to platform
            </a>
        </div>
    </header>

    <div class="body">
        {{-- Countdown phase --}}
        <section class="countdown" id="countdown" @if($snapshot['doorsOpen']) hidden @endif>
            <div class="eyebrow">Doors open in</div>
            <div class="clock" id="clock">
                <div class="unit"><span class="num" data-cd="days">00</span><span class="cap">Days</span></div>
                <span class="sep">:</span>
                <div class="unit"><span class="num" data-cd="hours">00</span><span class="cap">Hours</span></div>
                <span class="sep">:</span>
                <div class="unit"><span class="num" data-cd="mins">00</span><span class="cap">Minutes</span></div>
                <span class="sep">:</span>
                <div class="unit"><span class="num" data-cd="secs">00</span><span class="cap">Seconds</span></div>
            </div>
            @if ($doorsAt !== null)
                <div class="when">{{ $doorsAt->format('l j F Y · H:i') }}</div>
            @else
                <div class="when">Doors time not set</div>
            @endif
            <div class="open-now" id="openNow" hidden>Doors are open</div>
        </section>

        {{-- Live phase --}}
        <section class="grid" id="grid" @unless($snapshot['doorsOpen']) hidden @endunless>
            <div class="left">
                <div class="headline">
                    <div class="scanned">
                        <span class="k">Tickets scanned</span>
                        <span class="n" id="statScanned">{{ number_format($snapshot['stats']['scanned']) }}</span>
                        <span class="sub"><b id="statOrders">{{ number_format($snapshot['stats']['checkedInOrders']) }}</b> orders through the door</span>
                    </div>
                    <div class="side">
                        <div class="figure figure--pct">
                            <span class="k">Of sold</span>
                            <span class="v" id="statPercent">{{ $snapshot['stats']['percent'] === null ? '—' : $snapshot['stats']['percent'].'%' }}</span>
                            <span class="bar"><i id="statProgress" style="width: {{ $snapshot['stats']['percent'] ?? 0 }}%"></i></span>
                        </div>
                        <div class="figure">
                            <span class="k">Total sold</span>
                            <span class="v" id="statSold">{{ number_format($snapshot['stats']['sold']) }}</span>
                        </div>
                    </div>
                </div>

                <div class="graph">
                    <div class="h">Orders scanned by operator</div>
                    <div class="bars" id="bars"></div>
                    <div class="empty" id="barsEmpty" hidden>No check-ins yet.</div>
                </div>
            </div>

            <div class="feed-col">
                <div class="h"><span class="beat"></span> Live feed</div>
                <div class="feed" id="feed"></div>
                <div class="empty" id="feedEmpty" hidden>Waiting for the first scan…</div>
            </div>
        </section>

        @if ($logoUrl)
            <img class="logo" src="{{ $logoUrl }}" alt="{{ $event->name }}">
        @else
            <span class="wordmark" aria-label="Events by CK Enterprises">
                <span class="events">Events</span>
                <span class="by">by</span>
                <span class="ck">CK Enterprises</span>
            </span>
        @endif
    </div>
</div>

<script>
(function () {
    var root = document.querySelector('.live');
    var FEED_URL = root.dataset.feedUrl;
    var doorsAt = parseInt(root.dataset.doorsAt, 10) || 0;   // epoch ms, 0 = none
    var doorsOpen = root.dataset.doorsOpen === '1';
    var POLL_MS = 2500;

    // Operator bar colours, cycled in rank order (top scanner → first colour).
    var BAR_COLOURS = ['var(--c1)','var(--c2)','var(--c3)','var(--c4)','var(--c5)','var(--c6)'];

    var elCountdown = document.getElementById('countdown');
    var elGrid = document.getElementById('grid');
    var elOpenNow = document.getElementById('openNow');
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
    var elBars = document.getElementById('bars');
    var elBarsEmpty = document.getElementById('barsEmpty');
    var elFeed = document.getElementById('feed');
    var elFeedEmpty = document.getElementById('feedEmpty');

    var lastTopId = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function nf(n) { return (n || 0).toLocaleString(); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function showPhase(open) {
        doorsOpen = open;
        elCountdown.hidden = open;
        elGrid.hidden = !open;
    }

    function tickCountdown() {
        if (doorsOpen || !doorsAt) return;
        var diff = doorsAt - Date.now();
        if (diff <= 0) {
            cd.days.textContent = cd.hours.textContent = cd.mins.textContent = cd.secs.textContent = '00';
            elClock.hidden = true;
            elOpenNow.hidden = false;
            return;
        }
        var s = Math.floor(diff / 1000);
        var d = Math.floor(s / 86400); s -= d * 86400;
        var h = Math.floor(s / 3600);  s -= h * 3600;
        var m = Math.floor(s / 60);    s -= m * 60;
        cd.days.textContent = pad(d);
        cd.hours.textContent = pad(h);
        cd.mins.textContent = pad(m);
        cd.secs.textContent = pad(s);
    }

    function renderStats(st) {
        elScanned.textContent = nf(st.scanned);
        elOrders.textContent = nf(st.checkedInOrders);
        elSold.textContent = nf(st.sold);
        elPercent.textContent = st.percent == null ? '—' : st.percent + '%';
        elProgress.style.width = (st.percent == null ? 0 : Math.min(100, st.percent)) + '%';
    }

    // Operator chart: bars by ORDERS scanned, ranked top-scanner-first, each
    // row a distinct colour. The server already sorts byStaff by orders desc,
    // but we sort again defensively so the display order is guaranteed.
    function renderOperators(rows) {
        if (!rows || !rows.length) { elBars.innerHTML = ''; elBarsEmpty.hidden = false; return; }
        elBarsEmpty.hidden = true;
        var sorted = rows.slice().sort(function (a, b) { return (b.orders || 0) - (a.orders || 0); });
        var max = sorted.reduce(function (m, r) { return Math.max(m, r.orders || 0); }, 0) || 1;
        elBars.innerHTML = sorted.map(function (r, i) {
            var pct = Math.round(((r.orders || 0) / max) * 100);
            var colour = BAR_COLOURS[i % BAR_COLOURS.length];
            return '<div class="bar-row">'
                + '<span class="nm" title="' + esc(r.name) + '">' + esc(r.name) + '</span>'
                + '<span class="trk"><span class="fil" style="width:' + pct + '%;background:' + colour + '"></span></span>'
                + '<span class="vl" style="color:' + colour + '">' + nf(r.orders) + '</span></div>';
        }).join('');
    }

    function outcomeClass(o) {
        if (o === 'success') return 'is-success';
        if (o === 'duplicate') return 'is-duplicate';
        return 'is-error';
    }

    function rowHtml(s) {
        var cls = outcomeClass(s.outcome);
        var title = s.name || s.reference || 'Unknown ticket';
        var metaBits = [];
        if (s.breakdown) metaBits.push(esc(s.breakdown));
        metaBits.push(esc(s.operator));
        var meta = metaBits.join('  ·  ');

        var flag = '';
        if (s.outcome === 'duplicate') flag = '<span class="flag">Duplicate · ' + esc(s.message) + '</span>';
        else if (s.outcome !== 'success') flag = '<span class="flag">' + esc(s.message) + '</span>';

        var count = s.outcome === 'success' && s.tickets
            ? '<span class="count">+' + nf(s.tickets) + '</span>'
            : '<span class="count count--none">·</span>';

        return '<div class="row ' + cls + '" data-id="' + s.id + '">'
            + '<span class="tick"></span>'
            + '<span class="main"><span class="name">' + esc(title) + '</span>'
            + '<span class="meta">' + meta + '</span>' + flag + '</span>'
            + '<span class="aside">' + count + '<span class="at">' + esc(s.atLabel) + '</span></span>'
            + '</div>';
    }

    function renderFeed(recent) {
        if (!recent || !recent.length) {
            if (!elFeed.children.length) elFeedEmpty.hidden = false;
            return;
        }
        elFeedEmpty.hidden = true;
        var topId = recent[0].id;
        if (topId === lastTopId && elFeed.children.length) return;  // nothing new
        lastTopId = topId;
        elFeed.innerHTML = recent.map(rowHtml).join('');
    }

    function apply(data) {
        if (data.doorsOpen !== doorsOpen) showPhase(data.doorsOpen);
        if (data.doorsOpen) {
            renderStats(data.stats);
            renderOperators(data.byOperator);
            renderFeed(data.recent);
        }
    }

    // ---- Connection status --------------------------------------------------
    // On a failed poll the page keeps showing the last good figures, so the
    // status pill says how fresh they are: "Updated Xs ago" while healthy,
    // "Reconnecting…" once updates stop arriving, and "Signed out" (a reload
    // button) when the session has expired, which never recovers by itself.
    var STALE_AFTER_MS = POLL_MS * 3;
    var elConn = document.getElementById('conn');
    var elConnText = document.getElementById('conn-text');
    var elConnAnnounce = document.getElementById('conn-announce');
    var lastOkAt = 0;
    var signedOut = false;
    var inFlight = false;
    var announced = '';

    function agoLabel(ms) {
        var s = Math.max(0, Math.round(ms / 1000));
        if (s < 60) return s + 's ago';
        var m = Math.floor(s / 60);
        return m + 'm ' + (s % 60) + 's ago';
    }

    function setConn(state, text) {
        elConn.className = 'conn' + (state === 'ok' ? '' : ' conn--' + state);
        elConn.disabled = state !== 'down';
        elConnText.textContent = text;
        // Announce state changes only, not the ticking seconds.
        if (state !== announced) {
            announced = state;
            elConnAnnounce.textContent = state === 'ok' ? 'Live updates connected'
                : state === 'stale' ? 'Live updates interrupted, reconnecting'
                : 'Signed out, live updates stopped. Reload to sign in again.';
        }
    }

    function renderConn() {
        if (signedOut) {
            setConn('down', 'Signed out · reload to sign in');
            return;
        }
        if (!lastOkAt) {
            setConn('stale', 'Connecting…');
            return;
        }
        var age = Date.now() - lastOkAt;
        if (age > STALE_AFTER_MS) {
            setConn('stale', 'Reconnecting… last update ' + agoLabel(age));
        } else {
            setConn('ok', 'Updated ' + agoLabel(age));
        }
    }

    elConn.addEventListener('click', function () { window.location.reload(); });

    function poll() {
        // Never stack requests: a slow server would otherwise get a growing
        // pile of overlapping polls from every open display.
        if (inFlight || signedOut) return;
        inFlight = true;

        fetch(FEED_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                // 401 = session expired/idle timeout, 419 = CSRF/session gone,
                // and a redirect to the sign-in page all mean the display will
                // never recover on its own.
                if (r.status === 401 || r.status === 419 || (r.redirected && /\/login/.test(r.url))) {
                    signedOut = true;
                    return null;
                }
                return r.ok ? r.json() : null;
            })
            .then(function (data) {
                if (data) {
                    apply(data);
                    lastOkAt = Date.now();
                }
            })
            .catch(function () { /* transient error: keep last good view, retry next tick */ })
            .then(function () {
                inFlight = false;
                renderConn();
            });
    }

    @if ($snapshot['doorsOpen'])
        renderStats(@json($snapshot['stats']));
        renderOperators(@json($snapshot['byOperator']));
        renderFeed(@json($snapshot['recent']));
    @endif

    setInterval(tickCountdown, 1000); tickCountdown();
    setInterval(renderConn, 1000); renderConn();
    setInterval(poll, POLL_MS); poll();
})();
</script>
</body>
</html>
