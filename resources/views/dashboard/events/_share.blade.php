{{-- Share panel: public link + downloadable QR code.
     Expects $publicUrl (string) and $event in scope. (Requirements 4.1, 4.2, 4.4) --}}
<div class="panel">
    <div class="panel__head"><h2>Share</h2></div>

    <div class="field">
        <label for="share-url">Public event link</label>
        <div class="field-row">
            <input id="share-url" type="text" class="share-url" readonly
                   value="{{ $publicUrl }}"
                   data-share-url
                   onfocus="this.select();">
            <button type="button" class="btn btn-outline btn-sm" data-copy-link data-copy-target="share-url">Copy link</button>
        </div>
    </div>

    <div class="form-actions">
        <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.qr', $event) }}">Download QR code</a>
    </div>

    @if ($event->isPublished())
        <p class="hint">This link and QR code are live to customers now.</p>
    @else
        <p class="hint muted">This link goes live once you publish the event.</p>
    @endif
</div>

@push('scripts')
<script>
    document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-copy-target'));
            if (!input) return;
            var done = function () {
                var original = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = original; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(done, function () {
                    input.focus(); input.select(); done();
                });
            } else {
                input.focus(); input.select();
                try { document.execCommand('copy'); } catch (e) {}
                done();
            }
        });
    });
</script>
@endpush
