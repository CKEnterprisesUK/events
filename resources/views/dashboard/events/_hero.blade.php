{{--
    Manage-event hero banner.

    Renders the event's hero image at the top of the manage-event page.
    Per-event poster takes precedence over the company-level poster
    (Requirements 5.4, 5.5, 5.6). When neither is set, renders nothing.

    Expects: $event (with the `company` relation loaded).
    Purely presentational — no JS.
--}}
@php
    $poster = $event->poster_path ?? $event->company->poster_path;
@endphp

@if ($poster)
    <div class="event-hero-banner"
         style="margin-bottom:1.5rem;border-radius:8px;overflow:hidden;">
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($poster) }}"
             alt="{{ $event->name }}"
             style="display:block;width:100%;max-height:220px;object-fit:cover;">
    </div>
@endif
