{{--
    Shared wrapper for the dedicated event-management screens (Overview,
    Details, Location, Tickets, Share, Orders, History, Branding, Sponsors,
    Questions). Each screen extends this layout and fills the `section` slot.

    Navigation lives in the MAIN dark sidebar: this layout emits the
    `event_nav` marker section, which tells layouts/dashboard.blade.php to swap
    the organisation nav for the event-context nav. There is no second nav
    column — the old floating section sidebar has been removed.

        [ event title + status pill + publish/manage actions ]
        [ hero banner (optional) ]
        [ @yield('section') ]

    Extends the dashboard layout so the top-level app chrome (header + primary
    sidebar) is reused. Expects $event and $readiness in scope (supplied by
    EventController::sharedViewData()). `$activeSection` selects the current
    sidebar item and is set by each screen via @section('active_section').
--}}
@extends('layouts.dashboard')

@section('title', $event->name)

{{-- Marker: tells the dashboard layout to render the event-context sidebar. --}}
@section('event_nav', '1')

@section('content')
    @php
        // Whether every blocking checklist item is satisfied. Computed once here
        // from the readiness report so the top bar's Publish button and the
        // setup checklist can never disagree about publishability. Presentation
        // only — EventController::publish() remains the source of truth.
        $blockingItems = array_values(array_filter($readiness->items(), fn ($i) => $i->blocking));
        $allRequiredMet = count(array_filter($blockingItems, fn ($i) => $i->satisfied)) === count($blockingItems);
    @endphp

    {{-- Title row: name, status pill, and the publish/unpublish/manage actions. --}}
    <div class="page-head">
        @include('dashboard.events._manage_topbar', ['event' => $event, 'allRequiredMet' => $allRequiredMet])
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @if (session('publish_errors'))
        <p class="status" data-status="warning" role="status">
            This event can’t be published yet — see the setup checklist on the Overview for what’s still needed.
        </p>
    @endif

    {{-- Hero banner (per-event poster, falling back to company poster). --}}
    @include('dashboard.events._hero', ['event' => $event])

    <div class="event-section">
        @yield('section')
    </div>
@endsection
