@extends('layouts.dashboard')

@section('title', 'Events')

@push('head')
<style>
    .cell-event { display: flex; align-items: center; gap: 0.7rem; }
    .cell-thumb { width: 48px; height: 48px; flex: none; border-radius: 6px; object-fit: cover; background: var(--surface-2, #f2f2f5); }
    .cell-event__meta { min-width: 0; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Events</h1>
        <div class="page-head__actions">
            <a class="btn" href="{{ route('dashboard.events.create') }}">New event</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <div class="panel">
        @if ($events->isEmpty())
            <div class="empty">
                <p>No events yet.</p>
                <a class="btn btn-sm" href="{{ route('dashboard.events.create') }}">Create your first event</a>
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">When</th>
                        <th scope="col">Status</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $event)
                        @php $poster = $event->poster_path ?? $event->company->poster_path; @endphp
                        <tr>
                            <td>
                                <div class="cell-event">
                                    @if ($poster)
                                        <img class="cell-thumb"
                                             src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($poster) }}"
                                             alt="Hero image for {{ $event->name }}">
                                    @endif
                                    <span class="cell-event__meta">
                                        <a class="cell-strong" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                                        @if ($event->venue)
                                            <span class="cell-dim">{{ $event->venue }}</span>
                                        @endif
                                    </span>
                                </div>
                            </td>
                            <td>{!! $event->starts_at ? e($event->starts_at->format('j M Y, H:i')) : '&mdash;' !!}</td>
                            <td>
                                <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                                    {{ $event->isPublished() ? 'Published' : 'Draft' }}
                                </span>
                            </td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.events.show', $event) }}">Manage</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
