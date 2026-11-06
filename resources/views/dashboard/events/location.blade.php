{{--
    "Where" screen: a single form owning venue name, location type, address and
    the map pin. Posts to the dedicated dashboard.events.location.update route,
    which validates only the location fields — so this form no longer needs to
    carry the event name as a hidden input. (Requirements 4.1, 4.3, 4.9)
--}}
@extends('layouts.event')

@section('active_section', 'location')

@section('section')
    @cannot('events')
        <div class="panel">
            <div class="panel__head"><h2>Location</h2></div>
            <dl class="stack">
                <div><dt class="muted">Venue</dt><dd>{{ $event->venue ?: '—' }}</dd></div>
                <div><dt class="muted">Address</dt><dd>{{ $event->address ?: '—' }}</dd></div>
            </dl>
        </div>
    @endcannot
    @can('events')
    <form method="POST" action="{{ route('dashboard.events.location.update', $event) }}" class="stack">
        @csrf
        @method('PATCH')
        @include('dashboard.events._location', ['event' => $event])
        <div class="form-actions">
            <button type="submit" class="btn">Save location</button>
        </div>
    </form>
    @endcan
@endsection
