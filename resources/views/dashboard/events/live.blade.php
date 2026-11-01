{{--
    Full-screen "live event" dashboard, shown on a display at the door during an
    Event. (Live event dashboard)

    Deliberately a STANDALONE page (it does NOT extend layouts/dashboard) so it
    owns the whole black screen with no app chrome. Two phases, switched
    client-side off the polled feed:

      BEFORE doors open → a very large countdown to the doors-opening time (the
        Event's start time), laid out like a venue display board.
      AFTER doors open  → a 2:1 split: the left two-thirds lead with one huge
        "scanned" figure plus the % of sold and total sold, above a per-operator
        bar graph; the right third is the live feed of EVERY scan — successes,
        duplicates and errors — newest first.

    A slim top bar offers "Return to platform"; the company/event logo sits
    bottom-right. The page polls `dashboard.events.live.feed` and reconciles the
    DOM, so it stays live without a manual refresh.

    Supplied by EventLiveController::show():
      $event    — the Event.
      $branding — EffectiveBranding (logo + primary colour).
      $doorsAt  — Carbon|null, the doors-opening target (Event start), or null.
      $snapshot — the embedded first-paint figures, same shape as the feed JSON.
--}}
@php
    use Illuminate\Support\Facades\Storage;

    $accent = $branding->hasPrimaryColour() ? $branding->primaryColour : '#e8b54d';
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
    {{-- A grotesque for text + a mono for the big numerals, so the figures read
         like a scoreboard/departure board rather than generic card UI. --}}
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800;900&family=Cabin+Sketch:wght@700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent: {{ $accent }};
            --bg: #050506;
            --ink: #f4f3ef;
            --ink-dim: #6f6e6a;
            --hair: rgba(255,255,255,.08);
            --ok: #4ba36a;
            --dup: #d2a53a;
            --err: #c0493f;
            --mono: 'Space Mono', ui-monospace, 'SFMono-Regular', Menlo, monospace;
            --sans: 'Archivo', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; height: 100%; background: var(--bg); color: var(--ink);
            font-family: var(--sans); overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .live { display: flex; flex-direction: column; height: 100vh; width: 100vw; }

        /* ---- Top bar: a thin ruled strip, not a floating pill ---- */
        .topbar {
            flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between;
            padding: .85rem 1.75rem; border-bottom: 1px solid var(--hair);
        }
        .topbar .mark { display: flex; align-items: baseline; gap: .9rem; min-width: 0; }
        .topbar .live-tag {
            font-family: var(--mono); font-size: .68rem; font-weight: 700; letter-spacing: .32em;
            text-transform: uppercase; color: var(--bg); background: var(--accent);
            padding: .25rem .5rem .2rem; border-radius: 2px; white-space: nowrap;
        }
        .topbar .event-name {
            font-weight: 700; font-size: 1.05rem; letter-spacing: -.01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .topbar .back {
            display: inline-flex; align-items: center; gap: .55rem; text-decoration: none;
            color: var(--ink-dim); font-size: .82rem; font-weight: 600; letter-spacing: .02em;
            white-space: nowrap; transition: color .15s;
        }
        .topbar .back:hover { color: var(--ink); }
        .topbar .back svg { width: 15px; height: 15px; }

        .body { flex: 1 1 auto; position: relative; min-height: 0; }

        /* ---- Countdown phase ---- */
        .countdown {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 2.5rem; padding: 2rem;
        }
        .countdown .eyebrow {
            font-family: var(--mono); font-size: 1rem; letter-spacing: .5em; text-transform: uppercase;
            color: var(--ink-dim); padding-left: .5em;
        }
        .clock { display: flex; align-items: stretch; gap: clamp(.75rem, 2.5vw, 2.5rem); }
        .clock .unit { display: flex; flex-direction: column; align-items: center; gap: 1rem; }
        .clock .num {
            font-family: var(--mono); font-weight: 700; font-variant-numeric: tabular-nums;
            font-size: clamp(4rem, 18vw, 17rem); line-height: .82; letter-spacing: -.02em;
            color: var(--ink);
        }
        .clock .cap {
            font-size: clamp(.7rem, 1vw, .85rem); letter-spacing: .3em; text-transform: uppercase;
            color: var(--ink-dim); font-weight: 600;
        }
        .clock .sep {
            align-self: flex-start; font-family: var(--mono); font-weight: 700; color: var(--accent);
            font-size: clamp(3rem, 12vw, 12rem); line-height: .9;
        }
        .countdown .when { font-size: clamp(1rem, 1.6vw, 1.35rem); color: var(--ink-dim); letter-spacing: .02em; }
        .countdown .open-now {
            font-size: clamp(2.5rem, 7vw, 6rem); font-weight: 900; letter-spacing: -.02em;
            color: var(--accent); text-transform: uppercase;
        }

        /* ---- Live phase: 2:1 split ---- */
        .grid {
            position: absolute; inset: 0; display: grid; grid-template-columns: 2fr 1fr;
        }
        .left {
            display: flex; flex-direction: column; min-height: 0;
            padding: clamp(1.25rem, 2.5vw, 2.75rem);
            border-right: 1px solid var(--hair);
        }

        /* Headline: the scanned number dominates everything. */
        .headline { display: flex; align-items: flex-end; gap: clamp(2rem, 5vw, 5rem); flex-wrap: wrap; }
        .scanned { display: flex; flex-direction: column; }
        .scanned .k {
            font-size: clamp(.8rem, 1vw, 1rem); letter-spacing: .28em; text-transform: uppercase;
            color: var(--ink-dim); font-weight: 600; margin-bottom: .4rem;
        }
        .scanned .n {
            font-family: var(--mono); font-weight: 700; font-variant-numeric: tabular-nums;
            font-size: clamp(5.5rem, 17vw, 18rem); line-height: .78; letter-spacing: -.03em;
            color: var(--ink);
        }
        .scanned .sub { margin-top: .6rem; color: var(--ink-dim); font-size: clamp(.85rem, 1.1vw, 1.05rem); font-weight: 500; }
        .scanned .sub b { color: var(--ink); font-weight: 700; }

        /* Two secondary figures stacked beside the big number. */
        .side { display: flex; flex-direction: column; gap: 1.75rem; padding-bottom: .5rem; }
        .figure .k {
            font-size: .72rem; letter-spacing: .24em; text-transform: uppercase; color: var(--ink-dim);
            font-weight: 600; margin-bottom: .25rem;
        }
        .figure .v {
            font-family: var(--mono); font-weight: 700; font-variant-numeric: tabular-nums;
            font-size: clamp(2rem, 4vw, 3.5rem); line-height: 1;
        }
        .figure--pct .v { color: var(--accent); }
        .figure .bar { height: 6px; width: clamp(140px, 16vw, 240px); background: rgba(255,255,255,.07); margin-top: .7rem; overflow: hidden; }
        .figure .bar > i { display: block; height: 100%; background: var(--accent); width: 0; transition: width .7s cubic-bezier(.2,.7,.2,1); }
        .figure--issues .v { color: var(--ink); }
        .figure--issues.has-issues .v { color: var(--err); }

        /* Operator graph fills the rest of the left column. */
        .graph { margin-top: auto; padding-top: clamp(1.5rem, 3vw, 2.75rem); display: flex; flex-direction: column; min-height: 0; }
        .graph .h {
            display: flex; align-items: baseline; justify-content: space-between;
            font-size: .72rem; letter-spacing: .24em; text-transform: uppercase; color: var(--ink-dim);
            font-weight: 600; margin-bottom: 1.1rem; border-top: 1px solid var(--hair); padding-top: 1rem;
        }
        .bars { display: flex; flex-direction: column; gap: .9rem; overflow-y: auto; }
        .bar-row { display: grid; grid-template-columns: minmax(80px, 20%) 1fr auto; align-items: center; gap: 1rem; }
        .bar-row .nm { font-weight: 600; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bar-row .trk { height: 14px; background: rgba(255,255,255,.06); overflow: hidden; }
        .bar-row .fil { height: 100%; background: var(--accent); min-width: 2px; transition: width .7s cubic-bezier(.2,.7,.2,1); }
        .bar-row .vl { font-family: var(--mono); font-weight: 700; font-variant-numeric: tabular-nums; font-size: 1.05rem; min-width: 2.75rem; text-align: right; }
        .empty { color: var(--ink-dim); font-size: .95rem; padding: 1rem 0; }

        /* ---- Right column: live feed ---- */
        .feed-col { display: flex; flex-direction: column; min-height: 0; padding: clamp(1.25rem, 2vw, 2rem) clamp(1rem, 1.5vw, 1.5rem) 0; }
        .feed-col .h {
            display: flex; align-items: center; gap: .6rem; font-size: .72rem; letter-spacing: .24em;
            text-transform: uppercase; color: var(--ink-dim); font-weight: 600; margin-bottom: 1.1rem;
        }
        .feed-col .h .beat { width: 8px; height: 8px; background: var(--accent); border-radius: 50%; animation: beat 1.6s ease-in-out infinite; }
        @keyframes beat { 0%,100% { opacity: .35; transform: scale(.8); } 50% { opacity: 1; transform: scale(1); } }
        .feed { flex: 1 1 auto; overflow-y: auto; display: flex; flex-direction: column; padding-bottom: 5rem; }

        /* Feed rows are ruled lines, not floating cards — reads like a ticker. */
        .row {
            display: grid; grid-template-columns: 4px 1fr auto; gap: .9rem; align-items: start;
            padding: .8rem .25rem; border-bottom: 1px solid var(--hair);
            animation: rowin .35s ease;
        }
        @keyframes rowin { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        .row .tick { align-self: stretch; background: var(--ink-dim); }
        .row.is-success .tick { background: var(--ok); }
        .row.is-duplicate .tick { background: var(--dup); }
        .row.is-error .tick { background: var(--err); }
        .row .main { min-width: 0; }
        .row .name { font-weight: 700; font-size: 1.02rem; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row .meta { color: var(--ink-dim); font-size: .82rem; margin-top: .2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .row .flag { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; margin-top: .3rem; display: inline-block; }
        .row.is-duplicate .flag { color: var(--dup); }
        .row.is-error .flag { color: var(--err); }
        .row .aside { display: flex; flex-direction: column; align-items: flex-end; gap: .25rem; }
        .row .at { font-family: var(--mono); font-size: .76rem; color: var(--ink-dim); white-space: nowrap; }
        .row .count { font-family: var(--mono); font-weight: 700; font-size: 1.15rem; line-height: 1; }
        .row.is-success .count { color: var(--ok); }
        .row .count--none { color: var(--ink-dim); }

        .logo {
            position: absolute; bottom: 1.25rem; right: 1.5rem; z-index: 5;
            max-height: 52px; max-width: 200px; opacity: .85; pointer-events: none;
            filter: drop-shadow(0 2px 10px rgba(0,0,0,.7));
        }

        /* "Events by CK Enterprises" wordmark fallback, bottom-right, when no
           event/company logo is uploaded. Mirrors the dashboard header lockup:
           Cabin Sketch for "Events"/"CK Enterprises", a small uppercase "by"
           between them. */
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
        <a class="back" href="{{ $platformUrl }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Return to platform
        </a>
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
                        <div class="figure figure--issues {{ $snapshot['stats']['issues'] > 0 ? 'has-issues' : '' }}" id="issuesFigure">
                            <span class="k">Issues</span>
                            <span class="v" id="statIssues">{{ number_format($snapshot['stats']['issues']) }}</span>
                        </div>
                    </div>
                </div>

                <div class="graph">
                    <div class="h"><span>Scanned by operator</span></div>
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
    var elIssues = document.getElementById('statIssues');
    var elIssuesFig = document.getElementById('issuesFigure');
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
        elIssues.textContent = nf(st.issues);
        elIssuesFig.classList.toggle('has-issues', (st.issues || 0) > 0);
    }

    function renderOperators(rows) {
        if (!rows || !rows.length) { elBars.innerHTML = ''; elBarsEmpty.hidden = false; return; }
        elBarsEmpty.hidden = true;
        var max = rows.reduce(function (m, r) { return Math.max(m, r.tickets || 0); }, 0) || 1;
        elBars.innerHTML = rows.map(function (r) {
            var pct = Math.round(((r.tickets || 0) / max) * 100);
            return '<div class="bar-row">'
                + '<span class="nm" title="' + esc(r.name) + '">' + esc(r.name) + '</span>'
                + '<span class="trk"><span class="fil" style="width:' + pct + '%"></span></span>'
                + '<span class="vl">' + nf(r.tickets) + '</span></div>';
        }).join('');
    }

    // Outcome → row class + display. Duplicates and errors read differently
    // from a clean check-in so the door team spots problems at a glance.
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

    function poll() {
        fetch(FEED_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data) apply(data); })
            .catch(function () { /* transient error: keep last good view, retry next tick */ });
    }

    @if ($snapshot['doorsOpen'])
        renderStats(@json($snapshot['stats']));
        renderOperators(@json($snapshot['byOperator']));
        renderFeed(@json($snapshot['recent']));
    @endif

    setInterval(tickCountdown, 1000); tickCountdown();
    setInterval(poll, POLL_MS); poll();
})();
</script>
</body>
</html>
