@extends('layouts.dashboard')

@section('title', 'Scanner')

@section('content')
    <section class="scanner">
        <h1>Ticket Scanner</h1>
        <p>Point your phone camera at a ticket QR code to check the attendee in.</p>

        {{--
            Camera surface. The scanner runs entirely in the phone browser — no
            app install (Requirement 16.1). If the browser denies camera
            permission we surface a "camera access required" message and perform
            no scan (Requirement 16.2).
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
            <div class="scan-result scan-result--{{ $result['status'] }}" role="status">
                <h2 class="scan-result__message">{{ $result['message'] }}</h2>

                @if ($result['status'] === 'already_scanned' && isset($result['scanned_at']))
                    <p class="scan-result__meta">
                        Previously scanned at
                        {{ $result['scanned_at']->format('Y-m-d H:i:s') }}.
                    </p>
                @endif

                @if ($result['status'] === 'checked_in')
                    <p class="scan-result__meta">
                        Checked in at {{ $result['scanned_at']->format('Y-m-d H:i:s') }}.
                    </p>

                    @isset($result['order'])
                        <p class="scan-result__ref">
                            Order {{ $result['order']->order_reference }}
                            for {{ $result['order']->customer_name }}.
                        </p>
                    @endisset

                    @if (! empty($result['breakdown']))
                        <table class="scan-result__breakdown">
                            <thead>
                                <tr><th>Ticket type</th><th>Quantity</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($result['breakdown'] as $line)
                                    <tr>
                                        <td>{{ $line['name'] }}</td>
                                        <td>{{ $line['quantity'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        @endisset
    </section>
@endsection

@push('head')
    <style>
        .scanner-camera {
            position: relative;
            width: 100%;
            max-width: 480px;
            margin: 1rem 0;
            background: #000;
            border-radius: 0.5rem;
            overflow: hidden;
            aspect-ratio: 3 / 4;
        }
        .scanner-camera video { width: 100%; height: 100%; object-fit: cover; }
        .scanner-hint { color: #6b7280; font-size: 0.875rem; }
        .scan-result { margin-top: 1.5rem; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e5e7eb; }
        .scan-result--checked_in { border-color: #16a34a; background: #f0fdf4; }
        .scan-result--already_scanned { border-color: #d97706; background: #fffbeb; }
        .scan-result--rejected,
        .scan-result--failed,
        .scan-result--invalid,
        .scan-result--unreadable { border-color: #b91c1c; background: #fef2f2; }
        .scan-result__breakdown { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
        .scan-result__breakdown th,
        .scan-result__breakdown td { text-align: left; padding: 0.25rem 0.5rem; border-bottom: 1px solid #e5e7eb; }
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
                            { fps: 10, qrbox: 250 },
                            onDecoded,
                            function () { /* per-frame decode failures are ignored */ }
                        ).catch(showPermissionDenied);
                    }
                })
                .catch(showPermissionDenied);
        })();
    </script>
@endpush
