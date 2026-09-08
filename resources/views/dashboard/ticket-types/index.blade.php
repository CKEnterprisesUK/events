@extends('layouts.dashboard')

@section('title', $event->name.': Ticket Types')

@section('content')
    <div class="page-head">
        <div>
            <p class="dash-eyebrow"><a class="panel__link" href="{{ route('dashboard.events.show', $event) }}">&larr; {{ $event->name }}</a></p>
            <h1>Ticket types</h1>
        </div>
        <div class="page-head__actions">
            <button type="button" class="btn" data-toggle="new-type">New ticket type</button>
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    {{-- Create form --}}
    <div class="panel form-panel" id="new-type" @if (! $errors->any()) hidden @endif>
        <div class="panel__head"><h2>Add a ticket type</h2></div>
        <form method="POST" action="{{ route('dashboard.events.ticket-types.store', $event) }}" class="stack">
            @csrf
            @include('dashboard.ticket-types._form', ['ticketType' => null])
            <div class="form-actions">
                <button type="submit" class="btn">Create ticket type</button>
                <button type="button" class="btn btn-outline" data-toggle="new-type">Cancel</button>
            </div>
        </form>
    </div>

    <div class="panel">
        @if ($ticketTypes->isEmpty())
            <div class="empty">
                <p>No ticket types yet.</p>
                <button type="button" class="btn btn-sm" data-toggle="new-type">Add your first ticket type</button>
            </div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="num">Price</th>
                        <th class="num">Capacity</th>
                        <th class="num">Available</th>
                        <th>Sale window</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ticketTypes as $ticketType)
                        <tr>
                            <td><span class="cell-strong">{{ $ticketType->name }}</span></td>
                            <td class="num">{{ $ticketType->isFree() ? 'Free' : number_format($ticketType->price_minor / 100, 2) }}</td>
                            <td class="num">{{ number_format($ticketType->capacity) }}</td>
                            <td class="num">{{ number_format($ticketType->availableQuantity()) }} remaining</td>
                            <td>
                                @if ($ticketType->sale_starts_at && $ticketType->sale_ends_at)
                                    <span class="cell-dim">{{ $ticketType->sale_starts_at->format('j M H:i') }} &ndash; {{ $ticketType->sale_ends_at->format('j M H:i') }}</span>
                                @else
                                    <span class="cell-dim">&mdash;</span>
                                @endif
                            </td>
                            <td class="num"><button type="button" class="panel__link" data-toggle="edit-type-{{ $ticketType->id }}">Edit</button></td>
                        </tr>
                        <tr id="edit-type-{{ $ticketType->id }}" hidden>
                            <td colspan="6" class="edit-cell">
                                <form method="POST" action="{{ route('dashboard.events.ticket-types.update', [$event, $ticketType]) }}" class="stack">
                                    @csrf
                                    @method('PUT')
                                    @include('dashboard.ticket-types._form', ['ticketType' => $ticketType])
                                    <div class="form-actions">
                                        <button type="submit" class="btn btn-sm">Save changes</button>
                                        <button type="button" class="btn btn-outline btn-sm" data-toggle="edit-type-{{ $ticketType->id }}">Cancel</button>
                                    </div>
                                </form>
                            </td>
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
