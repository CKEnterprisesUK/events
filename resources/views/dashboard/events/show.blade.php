@extends('layouts.dashboard')

@section('title', $event->name)

@section('content')
    <section>
        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        <h1>{{ $event->name }}</h1>
        <p>Status: {{ $event->isPublished() ? 'Published' : 'Draft' }}</p>

        @if ($event->venue)
            <p>Venue: {{ $event->venue }}</p>
        @endif
        @if ($event->starts_at)
            <p>Starts: {{ $event->starts_at }}</p>
        @endif
        <p>Capacity: {{ $event->capacity ?? 'Unlimited' }}</p>

        @if ($event->description)
            <p>{{ $event->description }}</p>
        @endif

        @unless ($event->isPublished())
            <form method="POST" action="{{ route('dashboard.events.publish', $event) }}">
                @csrf
                <button type="submit">Publish</button>
            </form>
        @else
            <form method="POST" action="{{ route('dashboard.events.unpublish', $event) }}">
                @csrf
                <button type="submit">Unpublish</button>
            </form>
        @endunless
    </section>
@endsection
