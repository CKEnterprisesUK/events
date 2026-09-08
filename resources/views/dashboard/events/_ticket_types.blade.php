{{--
    Ticket types tab — self-contained inline ticket-type management for embedding
    in the manage-event tab shell. Moved from dashboard/ticket-types/index.

    In scope:
      $event          — the Event being managed
      $ticketTypes    — Collection<TicketType>
      $eventRemaining — ?int overall event remaining (null = unlimited / no event cap)

    Posts to the existing dashboard.events.ticket-types.{store,update} routes,
    which redirect back to the show page's Ticket types tab. (Requirements 6.1, 6.3, 2.6)
--}}
@php $eventRemaining = $eventRemaining ?? null; @endphp

<div class="tab-head">
    <h2>Ticket types</h2>
    <button type="button" class="btn btn-sm" data-toggle="new-type">New ticket type</button>
</div>

@if (session('status'))
    <p class="status">{{ session('status') }}</p>
@endif

{{-- Create form --}}
<div class="panel form-panel" id="new-type" @if (! $errors->any()) hidden @endif>
    <div class="panel__head"><h3>Add a ticket type</h3></div>
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
                    <th>Mode</th>
                    <th>Capacity / Available</th>
                    <th>Sale window</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ticketTypes as $ticketType)
                    @php $available = $ticketType->availabilityFor($eventRemaining); @endphp
                    <tr>
                        <td><span class="cell-strong">{{ $ticketType->name }}</span></td>
                        <td class="num">{{ $ticketType->isFree() ? 'Free' : number_format($ticketType->price_minor / 100, 2) }}</td>
                        <td>{{ $ticketType->isSharedPool() ? 'Shared pool' : 'Capped' }}</td>
                        <td>
                            @if ($ticketType->isSharedPool())
                                <span class="cell-strong">Shared pool</span>
                                @if ($eventRemaining !== null)
                                    <span class="cell-dim">{{ number_format($eventRemaining) }} remaining (event pool)</span>
                                @else
                                    <span class="cell-dim">Unlimited (no event cap)</span>
                                @endif
                            @else
                                <span class="cell-strong">{{ number_format((int) $available) }} remaining</span>
                                <span class="cell-dim">of {{ number_format((int) $ticketType->capacity) }}</span>
                            @endif
                        </td>
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

@push('scripts')
<script>
    // Idempotent [data-toggle] handler. Multiple partials may push an equivalent
    // block; the window flag guards against binding the same behaviour twice
    // (double-bound handlers would toggle twice = a net no-op).
    if (! window.__dataToggleBound) {
        window.__dataToggleBound = true;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-toggle]');
            if (! btn) return;
            var el = document.getElementById(btn.getAttribute('data-toggle'));
            if (el) {
                el.hidden = ! el.hidden;
                if (! el.hidden) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }
</script>
@endpush
