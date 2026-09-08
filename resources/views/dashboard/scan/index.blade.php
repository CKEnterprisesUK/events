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
            The decoded payload is POSTed here. The client-side decoder sets the
            hidden field's value and submits; the button also lets an operator
            submit a manually entered/pasted payload if the camera is
            unavailable. The server (ScanController@scan) is authoritative.
        --}}
        <form id="scan-form" method="POST" action="{{ route('dashboard.scan.submit') }}">
            @csrf
            <div class="field">
                <label for="scan-payload">Scanned code</label>
                <input type="text" id="scan-payload" name="payload" autocomplete="off"
                       value="{{ old('payload') }}" />
            </div>
            <button type="submit" class="btn">Check in</button>
        </form>

        @isset($result)
            @php
                // Map every outcome onto one of three door signals so the whole
                // banner reads as a single colour the operator can act on at a
                // glance: green = let them in, yellow = already scanned, red =
                // do not admit.
                $signal = match ($result['status']) {
                    'checked_in' => 'good',
                    'already_scanned' => 'warn',
                    default => 'bad',
                };

                // Total tickets on the order, summed from the type breakdown so
                // the door can confirm how many people this one code admits.
                $ticketCount = collect($result['breakdown'] ?? [])->sum('quantity');
            @endphp

            <div class="scan-result scan-result--{{ $signal }}" role="status" aria-live="polite">
                <p class="scan-result__message">{{ $result['message'] }}</p>

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
                        {{-- Per-level breakdown: which ticket type(s) and how
                             many of each, so the door knows the access level. --}}
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
                    <p class="scan-result__meta">
                        Previously scanned at
                        {{ $result['scanned_at']->format('Y-m-d H:i:s') }}.
                    </p>
                @endif
            </div>
        @endisset

        {{-- Recent-scan recap (session-backed, last few scans). --}}
        @include('dashboard.scan._history', ['history' => $history ?? []])
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
    </style>
@endpush

@push('scripts')
    {{--
        A QR decoder library is loaded client-side (no server build/bundle
        step). On decode, the payload is written into the hidden field and the
        form is submitted to the server, which performs the authoritative
        verify + atomic check-in. If camera permission is denied, we reveal the
        "camera access required" message and never attempt a scan.
    --}}
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        (function () {
            var statusEl = document.getElementById('scanner-status');
            var deniedEl = document.getElementById('scanner-permission-denied');
            var cameraEl = document.getElementById('scanner-camera');
            var payloadEl = document.getElementById('scan-payload');
            var formEl = document.getElementById('scan-form');
            var submitted = false;

            function showPermissionDenied() {
                // Requirement 16.2 — no camera access: show the message, no scan.
                if (deniedEl) { deniedEl.hidden = false; }
                if (cameraEl) { cameraEl.style.display = 'none'; }
                if (statusEl) { statusEl.hidden = true; }
            }

            function onDecoded(decodedText) {
                if (submitted) { return; }
                submitted = true;
                payloadEl.value = decodedText;
                formEl.submit();
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
                        var scanner = new window.Html5Qrcode('scanner-camera');
                        scanner.start(
                            { facingMode: 'environment' },
                            { fps: 10, qrbox: 180 },
                            onDecoded,
                            function () { /* per-frame decode failures are ignored */ }
                        ).catch(showPermissionDenied);
                    }
                })
                .catch(showPermissionDenied);
        })();
    </script>
@endpush
