@extends('layouts.dashboard')

@section('title', 'Events')

@section('content')
    <div class="page-head">
        <h1>Events</h1>
        <div class="page-head__actions">
            <button type="button" class="btn" data-toggle="new-event">New event</button>
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    {{-- Create-event form, revealed by the "New event" button. --}}
    <div class="panel form-panel" id="new-event" @if (! $errors->any()) hidden @endif>
        <div class="panel__head"><h2>Create an event</h2></div>
        <form method="POST" action="{{ route('dashboard.events.store') }}" class="stack">
            @csrf
            @include('dashboard.events._form', ['event' => null])
            <div class="form-actions">
                <button type="submit" class="btn">Create event</button>
                <button type="button" class="btn btn-outline" data-toggle="new-event">Cancel</button>
            </div>
        </form>
    </div>

    <div class="panel">
        @if ($events->isEmpty())
            <div class="empty">
                <p>No events yet.</p>
                <button type="button" class="btn btn-sm" data-toggle="new-event">Create your first event</button>
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
                        <tr>
                            <td>
                                <a class="cell-strong" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                                @if ($event->venue)
                                    <span class="cell-dim">{{ $event->venue }}</span>
                                @endif
                            </td>
                            <td>{{ $event->starts_at ? $event->starts_at->format('j M Y, H:i') : '—' }}</td>
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

@push('scripts')
<script>
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-toggle'));
            if (el) { el.hidden = !el.hidden; if (!el.hidden) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        });
    });
</script>
@endpush
