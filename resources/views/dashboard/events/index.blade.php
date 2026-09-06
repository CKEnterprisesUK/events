@extends('layouts.app')

@section('title', 'Events')

@section('content')
    <section>
        <h1>Events</h1>

        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        @if ($events->isEmpty())
            <p>No events yet.</p>
        @else
            <ul>
                @foreach ($events as $event)
                    <li>
                        <a href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                        &mdash; {{ $event->isPublished() ? 'Published' : 'Draft' }}
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
