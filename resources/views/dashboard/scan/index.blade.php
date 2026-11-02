@extends('layouts.dashboard')

@section('title', 'Scanner')

@section('content')
    {{--
        The live scanner. Reached from the intermediary start page
        (dashboard.scan.index), so it drops the heading and helper text and
        gives the camera + result banner the whole screen. A compact "Done"
        link returns to the start page and its recent-scan recap.
    --}}
    <section class="scanner">
        <a class="scanner__back" href="{{ route('dashboard.scan.index') }}">&larr; Done</a>

        {{--
            Camera surface. The scanner runs entirely in the phone browser — no
            app install (Requirement 16.1). If the browser denies camera
            permission we surface a "camera access required" message and perform
            no scan (Requirement 16.2). The surface is kept compact so the
            check-in result is the focus of the screen, not the viewfinder.
        --}}
        <div id="scanner-camera" class="scanner-camera">
            <video id="scanner-video" playsinline muted></video>
        </div>

        <p id="scanner-permission-denied" class="error" hidden>
            Camera access is required to scan tickets. Please allow camera
            access in your browser and reload the page.
        </p>

        <p id="scanner-status" class="scanner-hint">Requesting camera access…</p>

        {{--
            The decoded payload is POSTed here. The client-side decoder writes
            the scanned value into the hidden field and submits automatically.

            The payload field is a HIDDEN input by default so the on-screen
            keyboard never pops up over the camera — the camera path needs no
            typing. Manual entry stays as a fallback for when the camera is
            unavailable, but it's collapsed behind the "Enter code manually"
            toggle and only then does a real text field (and the keyboard)
            appear. The server (ScanController@scan) is authoritative.
        --}}
        <form id="scan-form" method="POST" action="{{ route('dashboard.scan.submit') }}">
            @csrf
            <input type="hidden" id="scan-payload" name="payload" value="{{ old('payload') }}" />

            <button type="button" id="scan-manual-toggle" class="scanner__manual-toggle">
                Enter code manually
            </button>

            <div id="scan-manual" class="field scanner__manual" hidden>
                <label for="scan-payload-manual">Order reference</label>
                <input type="text" id="scan-payload-manual" autocomplete="off"
                       autocapitalize="characters" autocorrect="off" spellcheck="false"
                       placeholder="e.g. A1B2C3D4E5F6" />
                <p class="scanner__manual-hint">
                    Type the order reference from the ticket if the QR code
                    won't scan.
                </p>
                <button type="submit" class="btn">Check in</button>
            </div>
        </form>

        {{--
            No-JS fallback: a plain POST re-renders the page with the result
            inline. The JS path never renders this — it shows the same banner
            in the overlay below instead, keeping the camera alive.
        --}}
        @isset($result)
            @include('dashboard.scan._result', ['result' => $result])
        @endisset

        {{--
            The dismissible result overlay for the JS path. It sits above the
            running camera; its body is filled from the scan response and the
            "Scan next" button hides it and re-arms the decoder. Hidden until a
            scan completes.
        --}}
        <div id="scan-overlay" class="scan-overlay" hidden role="dialog"
             aria-modal="true" aria-label="Scan result">
            <div class="scan-overlay__panel">
                <div id="scan-overlay-body"></div>
                <button type="button" id="scan-dismiss" class="btn scan-overlay__dismiss">
                    Scan next
                </button>
            </div>
        </div>

        {{-- Recent-scan recap (session-backed, last few scans). --}}
        <div id="scan-history-region">
            @include('dashboard.scan._history', ['history' => $history ?? []])
        </div>
    </section>
@endsection

@push('head')
    <style>
        .scanner__back {
            display: inline-block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            color: #6b7280;
            text-decoration: none;
        }
        .scanner__back:hover { text-decoration: underline; }
        .scanner__manual-toggle {
            background: none;
            border: none;
            padding: 0;
            margin: 0.25rem 0 0;
            color: #6b7280;
            font-size: 0.85rem;
            text-decoration: underline;
            cursor: pointer;
        }
        .scanner__manual { margin-top: 0.75rem; }
        .scanner__manual-hint {
            margin: 0.35rem 0 0.5rem;
            color: #6b7280;
            font-size: 0.8rem;
        }
        .scanner-camera {
            position: relative;
            width: 100%;
            max-width: 260px;
            margin: 1rem 0;
            background: #000;
            border-radius: 0.5rem;
            overflow: hidden;
            aspect-ratio: 1 / 1;
        }
        .scanner-camera video { width: 100%; height: 100%; object-fit: cover; }
        .scanner-hint { color: #6b7280; font-size: 0.875rem; }

        /* The result banner fills with a single door signal colour so the
           outcome is unmistakable at arm's length:
             green = admit, yellow = already scanned, red = do not admit. */
        .scan-result {
            margin-top: 1.5rem;
            padding: 1.5rem;
            border-radius: 0.75rem;
            border: 2px solid transparent;
            color: #fff;
        }
        .scan-result--good { background: #16a34a; border-color: #15803d; }
        .scan-result--warn { background: #f59e0b; border-color: #d97706; color: #1f2937; }
        .scan-result--bad  { background: #dc2626; border-color: #b91c1c; }
        /* Accidental immediate re-scan: a stronger amber with a thicker border
           so a quick double-scan of the same ticket is obvious at a glance. */
        .scan-result--just-scanned { background: #fbbf24; border-color: #b45309; border-width: 3px; }

        .scan-result__message {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .scan-result__count {
            margin: 0.75rem 0 0;
            font-size: 1.125rem;
            font-weight: 600;
        }
        .scan-result__count-num {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            vertical-align: -0.15em;
        }
        .scan-result__ref {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        .scan-result__levels {
            list-style: none;
            margin: 0.75rem 0 0;
            padding: 0.5rem 0 0;
            border-top: 1px solid rgba(255, 255, 255, 0.35);
        }
        .scan-result--warn .scan-result__levels {
            border-top-color: rgba(31, 41, 55, 0.25);
        }
        .scan-result__level {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.35rem 0;
            font-size: 1.05rem;
        }
        .scan-result__level-name { font-weight: 500; }
        .scan-result__level-qty { font-weight: 700; }
        .scan-result__meta {
            margin: 0.75rem 0 0;
            opacity: 0.85;
            font-size: 0.85rem;
        }

        /* The dismissible result overlay sits above the running camera so the
           operator sees the outcome without the camera tearing down. "Scan
           next" closes it and re-arms the decoder. */
        .scan-overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(17, 24, 39, 0.6);
        }
        .scan-overlay[hidden] { display: none; }
        .scan-overlay__panel {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 0.9rem;
            padding: 1rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        /* The banner already carries its own colour; inside the panel it needs
           no extra top margin. */
        .scan-overlay__panel .scan-result { margin-top: 0; }
        .scan-overlay__dismiss {
            display: block;
            width: 100%;
            margin-top: 1rem;
        }
    </style>
@endpush

@push('scripts')
    {{--
        A QR decoder library is loaded client-side (no server build/bundle
        step). On decode we POST the payload in the BACKGROUND (fetch) so the
        camera keeps running, then show the server's authoritative result in a
        dismissible overlay. "Scan next" closes the overlay and re-arms the
        decoder for the next person — no page reload, no camera restart. If
        JavaScript is unavailable the form still submits normally and the server
        re-renders the page with the result inline. If camera permission is
        denied we reveal the "camera access required" message and never scan.
    --}}
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        (function () {
            var statusEl = document.getElementById('scanner-status');
            var deniedEl = document.getElementById('scanner-permission-denied');
            var cameraEl = document.getElementById('scanner-camera');
            var payloadEl = document.getElementById('scan-payload');
            var formEl = document.getElementById('scan-form');
            var manualToggleEl = document.getElementById('scan-manual-toggle');
            var manualEl = document.getElementById('scan-manual');
            var manualInputEl = document.getElementById('scan-payload-manual');
            var overlayEl = document.getElementById('scan-overlay');
            var overlayBodyEl = document.getElementById('scan-overlay-body');
            var dismissEl = document.getElementById('scan-dismiss');
            var historyRegionEl = document.getElementById('scan-history-region');

            // Guards the whole result cycle: true from the moment a payload is
            // sent until the operator dismisses the overlay, so a code lingering
            // in frame (or a double tap) cannot fire a second scan.
            var busy = false;
            var scanner = null;
            var scanning = false;

            // Pause the live decoder while a result is on screen so the camera
            // isn't firing onDecoded behind the overlay. Best-effort: if pause
            // isn't supported the busy flag still blocks re-entry.
            function pauseDecoder() {
                if (scanner && scanning) {
                    try { scanner.pause(true); } catch (e) { /* ignore */ }
                }
            }

            function resumeDecoder() {
                if (scanner && scanning) {
                    try { scanner.resume(); } catch (e) { /* ignore */ }
                }
            }

            function showOverlay(resultHtml) {
                if (!overlayEl || !overlayBodyEl) { return; }
                overlayBodyEl.innerHTML = resultHtml;
                overlayEl.hidden = false;
                if (dismissEl) { dismissEl.focus(); }
            }

            function dismissOverlay() {
                if (overlayEl) { overlayEl.hidden = true; }
                if (overlayBodyEl) { overlayBodyEl.innerHTML = ''; }
                busy = false;
                resumeDecoder();
                if (statusEl) { statusEl.textContent = 'Scanning…'; }
            }

            if (dismissEl) {
                dismissEl.addEventListener('click', dismissOverlay);
            }

            // Read the CSRF token the form already carries so the background
            // POST is accepted just like a normal form submit.
            function csrfToken() {
                var field = formEl ? formEl.querySelector('input[name="_token"]') : null;
                return field ? field.value : '';
            }

            // POST the payload in the background and show the result overlay.
            // Falls back to a normal navigation submit if fetch is unavailable.
            function submitPayload(payload) {
                if (!payload) { return; }
                busy = true;
                pauseDecoder();
                if (statusEl) { statusEl.textContent = 'Checking…'; }

                if (!window.fetch) {
                    payloadEl.value = payload;
                    formEl.submit();
                    return;
                }

                var body = new FormData();
                body.append('_token', csrfToken());
                body.append('payload', payload);

                fetch(formEl.action, {
                    method: 'POST',
                    body: body,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    credentials: 'same-origin'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        showOverlay(data.resultHtml || '');
                        if (historyRegionEl && typeof data.historyHtml === 'string') {
                            historyRegionEl.innerHTML = data.historyHtml;
                        }
                    })
                    .catch(function () {
                        // Network/parse failure: fall back to a full submit so
                        // the operator still gets an authoritative result.
                        payloadEl.value = payload;
                        formEl.submit();
                    });
            }

            // Manual entry is opt-in: the keyboard only appears once the
            // operator taps "Enter code manually". Until then the payload field
            // is hidden, so the camera path never raises the on-screen keyboard.
            if (manualToggleEl && manualEl && manualInputEl) {
                manualToggleEl.addEventListener('click', function () {
                    manualEl.hidden = false;
                    manualToggleEl.hidden = true;
                    manualInputEl.focus();
                });
            }

            // Intercept the submit so a manual entry goes through the same
            // background flow as a scan. preventDefault only runs when JS is
            // alive, so a no-JS browser still posts and gets the inline result.
            if (formEl) {
                formEl.addEventListener('submit', function (e) {
                    if (busy) { e.preventDefault(); return; }
                    var manual = (manualEl && !manualEl.hidden) ? manualInputEl.value : '';
                    if (manual) {
                        e.preventDefault();
                        submitPayload(manual);
                    }
                });
            }

            function showPermissionDenied() {
                // Requirement 16.2 — no camera access: show the message, no scan.
                if (deniedEl) { deniedEl.hidden = false; }
                if (cameraEl) { cameraEl.style.display = 'none'; }
                if (statusEl) { statusEl.hidden = true; }
            }

            function onDecoded(decodedText) {
                if (busy) { return; }
                submitPayload(decodedText);
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                showPermissionDenied();
                return;
            }

            // Confirm camera permission before starting the decoder. A denied
            // prompt surfaces the "camera access required" message.
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function (stream) {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    if (statusEl) { statusEl.textContent = 'Scanning…'; }

                    if (window.Html5Qrcode) {
                        scanner = new window.Html5Qrcode('scanner-camera');
                        scanner.start(
                            { facingMode: 'environment' },
                            { fps: 10, qrbox: 180 },
                            onDecoded,
                            function () { /* per-frame decode failures are ignored */ }
                        ).then(function () { scanning = true; })
                         .catch(showPermissionDenied);
                    }
                })
                .catch(showPermissionDenied);
        })();
    </script>
@endpush
