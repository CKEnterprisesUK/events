{{--
    "Where" screen: a single form owning venue name, location type, address and
    the map pin. Posts to the dedicated dashboard.events.location.update route,
    which validates only the location fields — so this form no longer needs to
    carry the event name as a hidden input. (Requirements 4.1, 4.3, 4.9)
--}}
@extends('layouts.event')

@section('active_section', 'location')

@section('section')
    <form method="POST" action="{{ route('dashboard.events.location.update', $event) }}" class="stack">
        @csrf
        @method('PATCH')
        @include('dashboard.events._location', ['event' => $event])
        <div class="form-actions">
            <button type="submit" class="btn">Save location</button>
        </div>
    </form>
@endsection
