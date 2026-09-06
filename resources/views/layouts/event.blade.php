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
@endsection
