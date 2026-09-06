{{--
    Overview screen (the event manage landing page). At-a-glance stats, the
    capacity advisory, and the core event-details edit form (name, when,
    capacity, description, hero image). Venue and full location now live on the
    dedicated "Where" screen. (Requirement 5.1)
--}}
@extends('layouts.event')

@section('active_section', 'overview')

@section('section')
    @include('dashboard.events._summary', ['event' => $event, 'report' => $report])
    @include('dashboard.events._capacity', ['capacity' => $capacity, 'event' => $event])

    <div class="panel form-panel">
        <div class="panel__head">
            <h2>Event details</h2>
        </div>
        <form id="event-details-form" method="POST"
              action="{{ route('dashboard.events.update', $event) }}"
              enctype="multipart/form-data" class="stack">
            @csrf
            @method('PUT')
            @include('dashboard.events._form', ['event' => $event])
            <div class="form-actions">
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>
    </div>
@endsection
