@extends('layouts.dashboard')

@section('title', 'Sharing')

@section('content')
    @php
        // Display-friendly host (scheme stripped) for showing URLs inline,
        // mirroring the dashboard's "Your storefront" panel.
        $displayHost = rtrim(preg_replace('#^https?://#', '', url('/')), '/');
        $storefrontDisplay = $displayHost . '/' . $company->slug;

        // The booking-feed embed snippet: a single iframe pointing at the
        // public booking widget for this company.
        $bookingEmbed = '<iframe src="' . e($bookingEmbedUrl) . '" '
            . 'style="width:100%;max-width:640px;height:640px;border:0;" '
            . 'loading="lazy" title="' . e($company->name) . ' — Book tickets"></iframe>';
    @endphp

    <div class="dash-head">
        <div>
            <p class="dash-eyebrow">Promote</p>
            <h1>Sharing</h1>
            <p class="muted">Share your storefront and drop your events straight onto your own website.</p>
        </div>
        <div class="dash-head__actions">
            <a class="btn" href="{{ $storefrontUrl }}" target="_blank" rel="noopener">View storefront</a>
        </div>
    </div>

    {{-- Storefront QR + share link ------------------------------------------- --}}
    <div class="panel">
        <div class="panel__head">
            <h2>Your storefront</h2>
        </div>
        <div class="share-store">
            <div class="share-store__qr">
                {{-- The QR endpoint streams a fresh PNG each load; also offered
                     as a direct download for print/marketing use. --}}
                <img src="{{ route('dashboard.sharing.qr') }}" alt="QR code linking to your storefront" width="180" height="180" />
            </div>
            <div class="share-store__body">
                <p class="muted">Point your camera at the code, or share the link, to open your public storefront where customers browse and buy tickets for all your events.</p>
                <a class="storefront-url" href="{{ $storefrontUrl }}" target="_blank" rel="noopener">{{ $storefrontDisplay }}</a>
                <div class="share-actions">
                    <a class="btn" href="{{ route('dashboard.sharing.qr') }}" download>Download QR code</a>
                    <button type="button" class="btn btn-secondary" data-copy="{{ $storefrontUrl }}">Copy link</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Booking widget (all events feed) ------------------------------------- --}}
    <div class="panel">
        <div class="panel__head">
            <h2>Booking widget — all events</h2>
        </div>
        <div class="share-embed">
            <p class="muted">Embed a live feed of every published event on your website. Selecting an event opens your booking page in a new tab.</p>
            <label class="share-embed__label" for="booking-embed">Copy this HTML</label>
            <textarea id="booking-embed" class="share-embed__code" rows="3" readonly>{{ $bookingEmbed }}</textarea>
            <div class="share-actions">
                <button type="button" class="btn" data-copy-target="booking-embed">Copy embed code</button>
                <a class="btn btn-secondary" href="{{ $bookingEmbedUrl }}" target="_blank" rel="noopener">Preview widget</a>
            </div>
        </div>
    </div>

    {{-- Per-event tickets widgets (accordions) ------------------------------- --}}
    <div class="panel">
        <div class="panel__head">
            <h2>Tickets widgets — per event</h2>
        </div>

        @if ($upcomingEvents->isEmpty())
            <div class="empty">
                <p>You have no published upcoming events yet.</p>
                <a class="btn" href="{{ route('dashboard.events.index') }}">Manage events</a>
            </div>
        @else
            <div class="accordions">
                @foreach ($upcomingEvents as $event)
                    @php
                        $ticketsEmbedUrl = route('embed.tickets', ['companySlug' => $company->slug, 'event' => $event->id]);
                        $ticketsEmbed = '<iframe src="' . e($ticketsEmbedUrl) . '" '
                            . 'style="width:100%;max-width:480px;height:520px;border:0;" '
                            . 'loading="lazy" title="' . e($event->name) . ' — Tickets"></iframe>';
                        $embedId = 'tickets-embed-' . $event->id;
                    @endphp
                    <details class="accordion">
                        <summary class="accordion__summary">
                            <span class="accordion__title">
                                <span class="cell-strong">{{ $event->name }}</span>
                                @if ($event->starts_at)
                                    <span class="cell-dim">{{ $event->starts_at->format('D, j M Y · H:i') }}</span>
                                @endif
                            </span>
                            <x-icon name="chevron" class="accordion__caret" />
                        </summary>
                        <div class="accordion__body">
                            <p class="muted">Embed this event's tickets on your website. Choosing tickets takes the buyer to checkout in a new tab.</p>
                            <label class="share-embed__label" for="{{ $embedId }}">Copy this HTML</label>
                            <textarea id="{{ $embedId }}" class="share-embed__code" rows="3" readonly>{{ $ticketsEmbed }}</textarea>
                            <div class="share-actions">
                                <button type="button" class="btn" data-copy-target="{{ $embedId }}">Copy embed code</button>
                                <a class="btn btn-secondary" href="{{ $ticketsEmbedUrl }}" target="_blank" rel="noopener">Preview widget</a>
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
    </div>
@endsection

@push('head')
<style>
    .dash-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .dash-head h1 { margin: 0.15rem 0 0.25rem; }
    .dash-head .muted { margin: 0; }
    .dash-eyebrow { text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.72rem; color: var(--muted); margin: 0; }

    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 0.75rem; overflow: hidden; margin-bottom: 1.5rem; }
    .panel__head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
    .panel__head h2 { margin: 0; font-size: 1.05rem; }

    .share-store { display: flex; gap: 1.5rem; padding: 1.25rem; flex-wrap: wrap; align-items: flex-start; }
    .share-store__qr {
        flex: none; padding: 0.75rem; background: #fff; border: 1px solid var(--border);
        border-radius: 0.75rem; line-height: 0;
    }
    .share-store__qr img { display: block; width: 180px; height: 180px; }
    .share-store__body { flex: 1 1 320px; display: flex; flex-direction: column; gap: 0.85rem; }
    .share-store__body .muted { margin: 0; }

    .storefront-url {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem;
        background: #f3f4f6; border: 1px solid var(--border); border-radius: 0.5rem;
        padding: 0.5rem 0.85rem; text-decoration: none; color: var(--ink); align-self: flex-start;
        overflow-wrap: anywhere;
    }
    .storefront-url:hover { border-color: var(--brand); color: var(--brand); }

    .share-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; }

    .share-embed { padding: 1.25rem; display: flex; flex-direction: column; gap: 0.75rem; }
    .share-embed .muted { margin: 0; }
    .share-embed__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .share-embed__code {
        width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem;
        background: #f3f4f6; border: 1px solid var(--border); border-radius: 0.5rem;
        padding: 0.65rem 0.85rem; color: var(--ink); resize: vertical; line-height: 1.5;
    }

    .accordions { display: flex; flex-direction: column; }
    .accordion { border-bottom: 1px solid var(--border); }
    .accordion:last-child { border-bottom: none; }
    .accordion__summary {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        padding: 1rem 1.25rem; cursor: pointer; list-style: none; user-select: none;
    }
    .accordion__summary::-webkit-details-marker { display: none; }
    .accordion__summary:hover { background: #f9fafb; }
    .accordion__title { display: flex; flex-direction: column; gap: 0.1rem; }
    .accordion__caret { color: var(--muted); transition: transform 0.15s ease; flex: none; }
    .accordion[open] .accordion__caret { transform: rotate(180deg); }
    .accordion__body {
        padding: 0 1.25rem 1.25rem; display: flex; flex-direction: column; gap: 0.75rem;
    }
    .accordion__body .muted { margin: 0; }

    .btn-secondary { background: transparent; color: var(--ink); border: 1px solid var(--border); }
    .btn-secondary:hover { border-color: var(--brand); color: var(--brand); }

    .copy-ok { color: #047857 !important; border-color: #047857 !important; }
</style>
@endpush

@push('scripts')
<script>
    (function () {
        function flash(button, label) {
            var original = button.textContent;
            button.textContent = label;
            button.classList.add('copy-ok');
            setTimeout(function () {
                button.textContent = original;
                button.classList.remove('copy-ok');
            }, 1600);
        }

        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            // Fallback for non-secure contexts.
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* noop */ }
            document.body.removeChild(ta);
            return Promise.resolve();
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-copy], [data-copy-target]');
            if (!button) return;

            var text = button.getAttribute('data-copy');
            if (text === null) {
                var target = document.getElementById(button.getAttribute('data-copy-target'));
                text = target ? target.value : '';
            }
            if (!text) return;

            copyText(text).then(function () { flash(button, 'Copied'); });
        });
    })();
</script>
@endpush
