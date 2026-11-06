@extends('layouts.dashboard')
@section('title', 'Super-Admin — Stress test')

@push('head')
<style>
    .st-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.9rem 1rem; align-items: end; }
    .st-grid .field { display: flex; flex-direction: column; gap: 0.3rem; }
    .st-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin-top: 1rem; }
    .st-inline { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: flex-end; }
    .st-stats { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    .st-stats th, .st-stats td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid var(--border); }
    .st-stats th { font-weight: 600; }
    .st-verdict { list-style: none; margin: 0.5rem 0 0; padding: 0; display: grid; gap: 0.35rem; }
    .st-log { max-height: 12rem; overflow: auto; white-space: pre-wrap; font-size: 0.85rem; }
    .st-note { font-size: 0.9rem; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Stress test</h1>
            <p>Simulate up to 10 door scanners plus live-dashboard viewers against this environment, from your browser. Non-production only.</p>
        </div>
    </div>

    @if (session('ops_status'))
        <div class="admin-panel">
            <div class="admin-panel__head"><h2>Result</h2></div>
            <div class="admin-panel__body"><pre style="white-space:pre-wrap">{{ session('ops_status') }}</pre></div>
        </div>
    @endif
    @if (session('ops_error'))
        <div class="admin-panel">
            <div class="admin-panel__body">
                <span class="admin-pill admin-pill--suspended">Error</span>
                <pre style="white-space:pre-wrap">{{ session('ops_error') }}</pre>
            </div>
        </div>
    @endif
    @if ($errors->any())
        <div class="admin-panel">
            <div class="admin-panel__body">
                <span class="admin-pill admin-pill--suspended">Error</span>
                <p>{{ $errors->first() }}</p>
            </div>
        </div>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Target</h2></div>
        <div class="admin-panel__body">
            <p>
                Environment <code>{{ $environment }}</code>, database <code>{{ $database }}</code>.
                @unless ($allowed)
                    <span class="admin-pill admin-pill--suspended">Not allowed in this environment</span>
                @endunless
            </p>
            <p class="muted">
                Fixture:
                @if ($status['seeded'])
                    <code>orders={{ $status['orders'] }}</code>,
                    <code>tickets={{ $status['tickets'] }}</code>,
                    <code>scanned={{ $status['scanned'] }}</code>,
                    <code>scan_events={{ $status['scan_events'] }}</code>
                @else
                    not seeded yet
                @endif
            </p>
            <p class="muted st-note">
                Per-operator scan limit is <code>{{ $scannerLimitPerMinute }}</code> scans per minute
                (<code>API_THROTTLE_SCAN</code>). Keep each scanner slower than that, or the run reports 429s.
                Watch cPanel &rarr; Resource Usage (Entry Processes, CPU) in another tab while a stage runs.
            </p>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>1. Fixture</h2></div>
        <div class="admin-panel__body">
            <p>
                Creates a separate <em>Stress Test Co</em> company with 10 scanner users, one live event and confirmed,
                unscanned orders (1&ndash;3 tickets each). Real companies are never touched. Seeding again replaces the fixture.
            </p>
            <form method="POST" action="{{ route('admin.stress-test.seed') }}" class="st-inline">
                @csrf
                <div class="field">
                    <label for="orders">Orders to seed</label>
                    <input type="number" name="orders" id="orders" min="{{ $minOrders }}" max="{{ $maxOrders }}"
                           value="{{ old('orders', $defaultOrders) }}" required>
                </div>
                <button type="submit" class="btn btn--primary">{{ $status['seeded'] ? 'Re-seed fixture' : 'Seed fixture' }}</button>
            </form>
            <p class="muted st-note">
                Use roughly your real ticket sales so the attendance query is realistic. Each order is scanned once, so a
                10-minute run at one scan every 2 seconds per scanner needs about 3,000 orders.
            </p>

            @if ($status['seeded'])
                <div class="st-actions">
                    <form method="POST" action="{{ route('admin.stress-test.reset-scans') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">Reset check-ins (between stages)</button>
                    </form>
                    <form method="POST" action="{{ route('admin.stress-test.teardown') }}"
                          onsubmit="return confirm('Remove the stress-test company, scanners, orders and tokens?');">
                        @csrf
                        <button type="submit" class="btn btn-outline">Remove fixture</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>2. Run a stage</h2></div>
        <div class="admin-panel__body">
            @unless ($status['seeded'])
                <p class="muted">Seed the fixture first.</p>
            @else
                <div id="stress-runner"
                     data-session-url="{{ route('admin.stress-test.session') }}"
                     data-scan-url="{{ url('/api/scan') }}"
                     data-live-url="{{ url('/api/dashboard/live') }}"
                     data-csrf="{{ csrf_token() }}">

                    <div class="st-grid">
                        <div class="field">
                            <label for="st-preset">Preset</label>
                            <select id="st-preset">
                                <option value="baseline">1. Baseline (expected door load)</option>
                                <option value="busy">2. Busy (queue surge)</option>
                                <option value="stress">3. Stress (headroom)</option>
                                <option value="break">4. Break point (find the limit)</option>
                            </select>
                        </div>
                        <div class="field"><label for="st-scanners">Scanners (1&ndash;10)</label><input type="number" id="st-scanners" min="1" max="10"></div>
                        <div class="field"><label for="st-scan-ms">Ms between scans, per scanner</label><input type="number" id="st-scan-ms" min="100" step="50"></div>
                        <div class="field"><label for="st-viewers">Dashboard viewers</label><input type="number" id="st-viewers" min="0" max="100"></div>
                        <div class="field"><label for="st-viewer-ms">Ms between viewer polls</label><input type="number" id="st-viewer-ms" min="500" step="100"></div>
                        <div class="field"><label for="st-duration">Duration (seconds)</label><input type="number" id="st-duration" min="10" max="1800"></div>
                        <div class="field"><label for="st-ramp">Ramp-up (seconds)</label><input type="number" id="st-ramp" min="0" max="600"></div>
                        <div class="field"><label for="st-dup">Duplicate scans (%)</label><input type="number" id="st-dup" min="0" max="50"></div>
                        <div class="field"><label for="st-bad">Invalid codes (%)</label><input type="number" id="st-bad" min="0" max="50"></div>
                        <div class="field"><label for="st-cap">Max requests in flight (1&ndash;24)</label><input type="number" id="st-cap" min="1" max="24"></div>
                        <div class="field"><label for="st-p95">Pass if p95 under (ms)</label><input type="number" id="st-p95" min="50" step="50" value="500"></div>
                    </div>

                    <p class="muted st-note">
                        The in-flight cap is a safety limit: when it is reached, further requests are skipped (and counted) instead of
                        piling up. It stays below the 25 Entry Process limit. The run also stops itself after 10 failures in a row.
                        Browsers on HTTP/1.1 only open about 6 connections per host, so very high loads are better driven from two
                        devices at once (use half the viewers on each).
                    </p>

                    <div class="st-actions">
                        <button type="button" class="btn btn--primary" id="st-start">Start stage</button>
                        <button type="button" class="btn btn-outline" id="st-stop" disabled>Stop</button>
                        <span id="st-state" class="muted">Idle</span>
                    </div>

                    <h3 style="margin-top:1.5rem">Live figures</h3>
                    <table class="st-stats">
                        <thead>
                            <tr><th scope="col">Endpoint</th><th scope="col">Sent</th><th scope="col">2xx</th><th scope="col">429</th><th scope="col">Other 4xx</th><th scope="col">5xx</th><th scope="col">Network</th><th scope="col">p50 ms</th><th scope="col">p95 ms</th><th scope="col">p99 ms</th><th scope="col">Max ms</th></tr>
                        </thead>
                        <tbody>
                            <tr id="st-row-scan"></tr>
                            <tr id="st-row-feed"></tr>
                        </tbody>
                    </table>
                    <p class="muted st-note" id="st-summary"></p>
                    <p class="muted st-note" id="st-outcomes"></p>

                    <div id="st-verdict-wrap" hidden>
                        <h3 style="margin-top:1.5rem">Verdict</h3>
                        <p id="st-verdict-title" role="status"></p>
                        <ul class="st-verdict" id="st-verdict"></ul>
                    </div>

                    <h3 style="margin-top:1.5rem">Log</h3>
                    <pre class="st-log" id="st-log">Ready.</pre>
                </div>
            @endunless
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    var root = document.getElementById('stress-runner');
    if (!root) { return; }

    var SESSION_URL = root.dataset.sessionUrl;
    var SCAN_URL = root.dataset.scanUrl;
    var LIVE_URL = root.dataset.liveUrl;
    var CSRF = root.dataset.csrf;

    var PRESETS = {
        baseline: { scanners: 10, scanMs: 2000, viewers: 2,  viewerMs: 2500, duration: 300, ramp: 20, dup: 5, bad: 5, cap: 20 },
        busy:     { scanners: 10, scanMs: 1000, viewers: 5,  viewerMs: 2500, duration: 300, ramp: 20, dup: 5, bad: 5, cap: 20 },
        stress:   { scanners: 10, scanMs: 650,  viewers: 10, viewerMs: 2500, duration: 300, ramp: 20, dup: 5, bad: 5, cap: 22 },
        'break':  { scanners: 10, scanMs: 650,  viewers: 30, viewerMs: 1500, duration: 300, ramp: 60, dup: 5, bad: 5, cap: 24 }
    };

    var FIELDS = {
        scanners: 'st-scanners', scanMs: 'st-scan-ms', viewers: 'st-viewers', viewerMs: 'st-viewer-ms',
        duration: 'st-duration', ramp: 'st-ramp', dup: 'st-dup', bad: 'st-bad', cap: 'st-cap'
    };

    function el(id) { return document.getElementById(id); }

    function applyPreset(name) {
        var p = PRESETS[name];
        Object.keys(FIELDS).forEach(function (key) { el(FIELDS[key]).value = p[key]; });
    }

    function num(id, min, max, fallback) {
        var v = parseInt(el(id).value, 10);
        if (isNaN(v)) { v = fallback; }
        return Math.max(min, Math.min(max, v));
    }

    function readConfig() {
        var duration = num('st-duration', 10, 1800, 300);
        return {
            scanners: num('st-scanners', 1, 10, 10),
            scanMs: num('st-scan-ms', 100, 600000, 2000),
            viewers: num('st-viewers', 0, 100, 0),
            viewerMs: num('st-viewer-ms', 500, 600000, 2500),
            duration: duration,
            ramp: num('st-ramp', 0, Math.min(600, duration), 0),
            dup: num('st-dup', 0, 50, 5),
            bad: num('st-bad', 0, 50, 5),
            cap: num('st-cap', 1, 24, 20),
            p95: num('st-p95', 50, 60000, 500)
        };
    }

    // ---- state -------------------------------------------------------------

    var run = null;

    function blankBucket() {
        return { sent: 0, ok: 0, s429: 0, s4xx: 0, s5xx: 0, net: 0, ms: [] };
    }

    function log(line) {
        var box = el('st-log');
        var stamp = new Date().toLocaleTimeString();
        box.textContent = (box.textContent === 'Ready.' ? '' : box.textContent + '\n') + stamp + '  ' + line;
        box.scrollTop = box.scrollHeight;
    }

    function percentile(sorted, p) {
        if (!sorted.length) { return null; }
        var idx = Math.min(sorted.length - 1, Math.ceil(p / 100 * sorted.length) - 1);
        return sorted[Math.max(0, idx)];
    }

    function summarise(bucket) {
        var sorted = bucket.ms.slice().sort(function (a, b) { return a - b; });
        return {
            p50: percentile(sorted, 50), p95: percentile(sorted, 95), p99: percentile(sorted, 99),
            max: sorted.length ? sorted[sorted.length - 1] : null
        };
    }

    function fmt(v) { return v === null ? '–' : String(Math.round(v)); }

    function renderRow(rowId, label, bucket) {
        var s = summarise(bucket);
        el(rowId).innerHTML =
            '<th scope="row">' + label + '</th><td>' + bucket.sent + '</td><td>' + bucket.ok + '</td><td>' + bucket.s429 +
            '</td><td>' + bucket.s4xx + '</td><td>' + bucket.s5xx + '</td><td>' + bucket.net +
            '</td><td>' + fmt(s.p50) + '</td><td>' + fmt(s.p95) + '</td><td>' + fmt(s.p99) + '</td><td>' + fmt(s.max) + '</td>';
    }

    function render() {
        if (!run) { return; }
        renderRow('st-row-scan', 'POST /api/scan', run.scan);
        renderRow('st-row-feed', 'GET /api/dashboard/live', run.feed);

        var elapsed = Math.max(1, ((run.endedAt || Date.now()) - run.startedAt) / 1000);
        var total = run.scan.sent + run.feed.sent;
        el('st-summary').textContent =
            'Elapsed ' + Math.round(elapsed) + 's of ' + run.cfg.duration + 's · ' + (total / elapsed).toFixed(1) + ' req/s · ' +
            run.inflight + ' in flight (peak ' + run.peakInflight + ') · skipped by cap: ' + run.skipped +
            (run.exhausted ? ' · fresh codes exhausted: ' + run.exhausted : '');

        var names = Object.keys(run.outcomes).sort();
        el('st-outcomes').textContent = names.length
            ? 'Scan outcomes: ' + names.map(function (n) { return n + ' ' + run.outcomes[n]; }).join(', ')
            : '';
    }

    // ---- requests ----------------------------------------------------------

    function record(bucket, status, ms) {
        bucket.sent++;
        if (status === 0) { bucket.net++; return; }
        bucket.ms.push(ms);
        if (status >= 200 && status < 300) { bucket.ok++; }
        else if (status === 429) { bucket.s429++; }
        else if (status >= 500) { bucket.s5xx++; }
        else { bucket.s4xx++; }
    }

    function send(kind, url, session, body) {
        if (!run || run.stopping) { return; }
        if (run.inflight >= run.cfg.cap) { run.skipped++; return; }

        var bucket = kind === 'scan' ? run.scan : run.feed;
        var started = performance.now();
        var thisRun = run;

        thisRun.inflight++;
        thisRun.peakInflight = Math.max(thisRun.peakInflight, thisRun.inflight);

        var init = {
            method: body ? 'POST' : 'GET',
            credentials: 'omit',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + session.token,
                'X-Device-Id': session.device_id
            }
        };
        if (body) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        fetch(url, init).then(function (res) {
            var ms = performance.now() - started;
            record(bucket, res.status, ms);
            noteHealth(thisRun, res.status);
            if (kind === 'scan' && res.status === 200) {
                return res.json().then(function (data) {
                    var key = (data && data.status) ? data.status : 'unknown';
                    thisRun.outcomes[key] = (thisRun.outcomes[key] || 0) + 1;
                }).catch(function () {});
            }
        }).catch(function () {
            record(bucket, 0, 0);
            noteHealth(thisRun, 0);
        }).then(function () {
            thisRun.inflight--;
        });
    }

    function noteHealth(thisRun, status) {
        if (status === 0 || status >= 500) {
            thisRun.failStreak++;
            if (thisRun.failStreak >= 10 && !thisRun.stopping) {
                log('Auto-stopped: 10 server or network failures in a row (last status ' + (status || 'network error') + ').');
                finish('aborted');
            }
        } else {
            thisRun.failStreak = 0;
        }
    }

    // ---- virtual users -----------------------------------------------------

    function jitter(ms) { return Math.max(50, ms * (0.8 + Math.random() * 0.4)); }

    function randomHex(len) {
        var out = '';
        while (out.length < len) { out += Math.floor(Math.random() * 16).toString(16); }
        return out;
    }

    function later(fn, ms) {
        var id = setTimeout(fn, ms);
        run.timers.push(id);
    }

    function startScanner(index, session, codes, step) {
        var next = index;

        function pickPayload() {
            var roll = Math.random() * 100;
            if (roll < run.cfg.bad) {
                return 'STRESS' + randomHex(6) + '.' + randomHex(16);
            }
            if (roll < run.cfg.bad + run.cfg.dup && run.used.length) {
                return run.used[Math.floor(Math.random() * run.used.length)];
            }
            if (next < codes.length) {
                var code = codes[next];
                next += step;
                run.used.push(code);
                return code;
            }
            run.exhausted++;
            return run.used.length ? run.used[Math.floor(Math.random() * run.used.length)] : 'STRESS000000.00';
        }

        function tick() {
            if (!run || run.stopping) { return; }
            send('scan', SCAN_URL, session, { payload: pickPayload(), event_id: run.eventId });
            later(tick, jitter(run.cfg.scanMs));
        }

        var delay = run.cfg.ramp > 0 ? Math.random() * run.cfg.ramp * 1000 : 0;
        later(tick, delay);
    }

    function startViewer(index, session) {
        function tick() {
            if (!run || run.stopping) { return; }
            send('feed', LIVE_URL + '?event=' + encodeURIComponent(run.eventId), session, null);
            later(tick, jitter(run.cfg.viewerMs));
        }
        var delay = run.cfg.ramp > 0 ? Math.random() * run.cfg.ramp * 1000 : 0;
        later(tick, delay);
    }

    // ---- lifecycle ---------------------------------------------------------

    function setRunning(isRunning) {
        el('st-start').disabled = isRunning;
        el('st-stop').disabled = !isRunning;
        Object.keys(FIELDS).concat(['st-preset', 'st-p95']).forEach(function (key) {
            var node = el(FIELDS[key] || key);
            if (node) { node.disabled = isRunning; }
        });
    }

    function start() {
        if (run) { return; }
        var cfg = readConfig();

        setRunning(true);
        el('st-state').textContent = 'Preparing tokens…';
        el('st-verdict-wrap').hidden = true;
        log('Requesting scanner tokens and ticket codes…');

        fetch(SESSION_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        }).then(function (result) {
            if (!result.ok) { throw new Error(result.data && result.data.message ? result.data.message : 'Could not start a session.'); }
            launch(cfg, result.data);
        }).catch(function (err) {
            log('Could not start: ' + err.message);
            el('st-state').textContent = 'Idle';
            setRunning(false);
        });
    }

    function launch(cfg, data) {
        var sessions = data.scanners;
        var scanners = Math.min(cfg.scanners, sessions.length);

        run = {
            cfg: cfg, eventId: data.event_id, startedAt: Date.now(),
            scan: blankBucket(), feed: blankBucket(), outcomes: {},
            inflight: 0, peakInflight: 0, skipped: 0, exhausted: 0, failStreak: 0,
            used: [], timers: [], stopping: false, ui: null
        };

        var expectedFresh = Math.round(scanners * (cfg.duration * 1000 / cfg.scanMs) * (1 - (cfg.dup + cfg.bad) / 100));
        log('Started: ' + scanners + ' scanners, ' + cfg.viewers + ' viewers, ' + cfg.duration + 's. ' +
            data.codes.length + ' fresh codes available for about ' + expectedFresh + ' fresh scans.');
        if (expectedFresh > data.codes.length) {
            log('Warning: not enough fresh orders for this stage. Later scans will repeat codes. Seed more orders or reset check-ins.');
        }
        if (cfg.scanMs < 60000 / Number({{ (int) $scannerLimitPerMinute }})) {
            log('Warning: scanning faster than the per-operator limit of {{ (int) $scannerLimitPerMinute }}/min. Expect 429s.');
        }

        for (var i = 0; i < scanners; i++) { startScanner(i, sessions[i], data.codes, scanners); }
        for (var v = 0; v < cfg.viewers; v++) { startViewer(v, sessions[v % sessions.length]); }

        el('st-state').textContent = 'Running';
        run.ui = setInterval(render, 1000);
        run.timers.push(setTimeout(function () { finish('completed'); }, cfg.duration * 1000));
        render();
    }

    function stop() { finish('stopped'); }

    function finish(reason) {
        if (!run || run.stopping) { return; }
        run.stopping = true;
        run.endedAt = Date.now();
        run.timers.forEach(clearTimeout);
        clearInterval(run.ui);

        var finished = run;
        el('st-state').textContent = 'Finishing…';

        // Let in-flight requests land before the final figures are frozen.
        setTimeout(function () {
            render();
            verdict(finished, reason);
            run = null;
            setRunning(false);
            el('st-state').textContent = reason === 'completed' ? 'Completed' : (reason === 'aborted' ? 'Aborted' : 'Stopped');
        }, 1500);
    }

    function verdict(r, reason) {
        var scan = summarise(r.scan);
        var feed = summarise(r.feed);
        var limit = r.cfg.p95;
        var checks = [
            { ok: scan.p95 !== null && scan.p95 <= limit, text: 'Scan p95 ' + fmt(scan.p95) + ' ms (limit ' + limit + ' ms)' },
            { ok: r.cfg.viewers === 0 || (feed.p95 !== null && feed.p95 <= limit), text: 'Dashboard feed p95 ' + fmt(feed.p95) + ' ms (limit ' + limit + ' ms)' },
            { ok: r.scan.s5xx + r.feed.s5xx === 0, text: '5xx responses: ' + (r.scan.s5xx + r.feed.s5xx) },
            { ok: r.scan.net + r.feed.net === 0, text: 'Network errors: ' + (r.scan.net + r.feed.net) },
            { ok: r.scan.s429 + r.feed.s429 === 0, text: '429 responses: ' + (r.scan.s429 + r.feed.s429) + ' (non-zero means this stage scanned faster than the per-operator limit)' },
            { ok: r.scan.s4xx + r.feed.s4xx === 0, text: 'Other 4xx responses: ' + (r.scan.s4xx + r.feed.s4xx) },
            { ok: r.skipped === 0, text: 'Requests skipped by the in-flight cap: ' + r.skipped + ' (non-zero means responses were slower than the request rate)' }
        ];
        var allOk = checks.every(function (c) { return c.ok; }) && reason === 'completed';

        el('st-verdict-title').textContent = (allOk ? 'PASS' : 'FAIL') +
            (reason === 'completed' ? '' : ' (run ' + reason + ' early)') +
            ' · ' + (r.scan.sent + r.feed.sent) + ' requests';
        el('st-verdict').innerHTML = checks.map(function (c) {
            return '<li><strong>' + (c.ok ? 'PASS' : 'FAIL') + '</strong> ' + c.text + '</li>';
        }).join('');
        el('st-verdict-wrap').hidden = false;
        log('Stage ' + reason + '. Reset check-ins before the next stage.');
    }

    el('st-preset').addEventListener('change', function () { applyPreset(this.value); });
    el('st-start').addEventListener('click', start);
    el('st-stop').addEventListener('click', stop);
    window.addEventListener('beforeunload', function (e) {
        if (run && !run.stopping) { e.preventDefault(); e.returnValue = ''; }
    });

    applyPreset('baseline');
    renderRow('st-row-scan', 'POST /api/scan', blankBucket());
    renderRow('st-row-feed', 'GET /api/dashboard/live', blankBucket());
})();
</script>
@endpush
