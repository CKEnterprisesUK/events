{{--
    Shared wrapper for the dedicated event-management screens (Overview,
    Location, Tickets, Share, Orders). Each screen extends this layout and fills
    the `section` slot; the wrapper supplies the consistent chrome around it:

      [ event hero banner                                   ]
      [ title + status pill + Branding action               ]
      ┌───────────────────────┬──────────────────────────────┐
      │ section nav (sidebar) │  @yield('section')            │
      │ + setup checklist     │                              │
      └───────────────────────┴──────────────────────────────┘

    Extends the dashboard layout so the top-level app chrome (header + primary
    sidebar) is unchanged. Expects $event and $readiness in scope (supplied by
    EventController::sharedViewData()). `$activeSection` selects the current nav
    item and is set by each screen via @section('active_section').
--}}
@extends('layouts.dashboard')

@section('title', $event->name)

@section('content')
    @php $activeSection = trim($__env->yieldContent('active_section', 'overview')); @endphp

    {{-- Title row: name, live/draft status, and per-event branding. --}}
    <div class="page-head">
        <div>
            <h1 style="margin:0;">{{ $event->name }}</h1>
            <p class="muted" style="margin:.35rem 0 0;">
                <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                    {{ $event->isPublished() ? 'Published' : 'Draft' }}
                </span>
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.index') }}">All events</a>
            @can('settings')
                <a class="btn btn-outline btn-sm" href="{{ route('dashboard.branding.event.edit', $event) }}">Branding</a>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @if (session('publish_errors'))
        <p class="status" data-status="warning" role="status">
            This event can’t be published yet — see the setup checklist for what’s still needed.
        </p>
    @endif

    {{-- Hero banner (per-event poster, falling back to company poster). --}}
    @include('dashboard.events._hero', ['event' => $event])

    {{-- Two-column: section nav + checklist on the left, screen content right. --}}
    <div class="event-manage">
        <aside class="event-manage__nav" aria-label="Manage event sections">
            @include('dashboard.events._nav', ['event' => $event, 'active' => $activeSection])
            @include('dashboard.events._publish_card', ['event' => $event, 'readiness' => $readiness])
        </aside>

        <div class="event-manage__body">
            @yield('section')
        </div>
    </div>

    @push('head')
    <style>
        /* Second-level layout for the event manage screens: a fixed-width nav
           column beside a fluid content column. Collapses to a single stacked
           column on narrow viewports. */
        .event-manage { display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; align-items: start; }
        .event-manage__nav { display: flex; flex-direction: column; gap: 1.25rem; }
        .event-manage__body { min-width: 0; }

        /* Section nav — a vertical list of links that reads as a sidebar rather
           than a row of buttons. Mirrors the primary sidebar's nav-link feel. */
        .section-nav { display: flex; flex-direction: column; gap: .15rem; }
        .section-nav__link { display: flex; align-items: center; gap: .6rem; padding: .55rem .7rem; border-radius: 7px; color: var(--text, #1f2430); text-decoration: none; font-weight: 500; }
        .section-nav__link:hover { background: var(--surface-2, #f2f2f5); }
        .section-nav__link.is-active { background: var(--brand, #2dd4bf); color: #06251f; }
        .section-nav__ico { flex: none; width: 1.25rem; text-align: center; }
        .section-nav__flag { margin-left: auto; font-size: .95rem; line-height: 1; }
        .section-nav__flag--todo { color: #d64545; }
        .section-nav__flag--done { color: #1a9c6e; }
        .section-nav__link.is-active .section-nav__flag--todo,
        .section-nav__link.is-active .section-nav__flag--done { color: inherit; }

        @media (max-width: 800px) {
            .event-manage { grid-template-columns: 1fr; }
            .section-nav { flex-direction: row; flex-wrap: wrap; }
            .section-nav__flag { margin-left: .35rem; }
        }
    </style>
    @endpush
@endsection
