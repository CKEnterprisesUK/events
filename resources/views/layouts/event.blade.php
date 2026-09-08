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
    @php
        $activeSection = trim($__env->yieldContent('active_section', 'overview'));

        // Whether every blocking checklist item is satisfied. Computed once here
        // from the readiness report so the top bar's Publish button and the
        // setup checklist card can never disagree about publishability. This is
        // presentation only — EventController::publish() remains the source of
        // truth via publishBlockers().
        $blockingItems = array_values(array_filter($readiness->items(), fn ($i) => $i->blocking));
        $allRequiredMet = count(array_filter($blockingItems, fn ($i) => $i->satisfied)) === count($blockingItems);
    @endphp

    {{-- Title row: name, live/draft status, and the publish/unpublish actions. --}}
    <div class="page-head">
        @include('dashboard.events._manage_topbar', ['event' => $event, 'allRequiredMet' => $allRequiredMet])
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
    <div class="event-manage" id="eventManage">
        <aside class="event-manage__nav" aria-label="Manage event sections">
            @include('dashboard.events._nav', ['event' => $event, 'active' => $activeSection])
            @include('dashboard.events._publish_card', ['event' => $event, 'readiness' => $readiness, 'allRequiredMet' => $allRequiredMet])
        </aside>

        <div class="event-manage__body">
            @yield('section')
        </div>
    </div>
@endsection
