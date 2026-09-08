{{--
    Dedicated "New event" screen. Deliberately minimal — just the essentials to
    get an event created (name, when, venue). Description, hero image, capacity,
    ticket types and full location are added afterwards on the manage screens,
    guided by the setup checklist. (Requirements 5.1, 5.2)
--}}
@extends('layouts.dashboard')

@section('title', 'New event')

@section('content')
    <div class="page-head">
        <div>
            <h1 style="margin:0;">New event</h1>
            <p class="muted" style="margin:.35rem 0 0;">Start with the basics. You can flesh it out next.</p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.index') }}">Cancel</a>
        </div>
    </div>

    <div class="panel form-panel" style="max-width:640px;">
        <div class="panel__head"><h2>Event basics</h2></div>
        <form method="POST" action="{{ route('dashboard.events.store') }}" class="stack">
            @csrf
            @include('dashboard.events._create_fields', ['event' => null])
            <div class="form-actions">
                <button type="submit" class="btn">Create event</button>
            </div>
            <p class="hint">After creating, you'll add the location, ticket types, hero image and more from the event's own screens.</p>
        </form>
    </div>
@endsection
