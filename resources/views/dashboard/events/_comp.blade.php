{{--
    Complimentary-ticket issuance form (Ticket types tab).

    Receives $event, $ticketTypes, and $eventRemaining (?int — the event's
    overall remaining capacity, null = unlimited) in scope. Posts to the
    unchanged dashboard.events.comp route, so the item[] input names are kept
    exactly as the original inline panel.

    Availability is mode-aware via $type->availabilityFor($eventRemaining):
      - capped:      an int (per-type remaining clamped by event remaining)
      - shared_pool: the event remaining (int) or null (unlimited)
    When null, the row shows "Shared pool" and the quantity input carries no
    max; otherwise the value is shown and used as the input's max.

    Requirements 6.2, 6.3, 6.5.
--}}
@can('issue_comp')
    <div class="panel form-panel panel--secondary">
        <div class="panel__head"><h2>Issue complimentary tickets</h2></div>
        @if ($ticketTypes->isEmpty())
            <div class="empty">
                <p>Add a ticket type before issuing complimentary tickets.</p>
                <a class="btn btn-sm" href="{{ route('dashboard.events.tickets', $event) }}">Add ticket type</a>
            </div>
        @else
            <form method="POST" action="{{ route('dashboard.events.comp', $event) }}" class="stack" id="comp-form">
                @csrf
                <div class="field-row">
                    <div class="field">
                        <label for="recipient_name">Recipient name</label>
                        <input id="recipient_name" type="text" name="recipient_name" required
                               value="{{ old('recipient_name') }}">
                        @error('recipient_name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="recipient_email">Recipient email</label>
                        <input id="recipient_email" type="email" name="recipient_email" required
                               value="{{ old('recipient_email') }}">
                        @error('recipient_email') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <label class="field-label">Quantities</label>
                <table class="data-table">
                    <thead>
                        <tr><th>Ticket type</th><th class="num">Available</th><th style="width:120px;">Quantity</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($ticketTypes as $i => $type)
                            @php $avail = $type->availabilityFor($eventRemaining); @endphp
                            <tr>
                                <td>
                                    <span class="cell-strong">{{ $type->name }}</span>
                                    <input type="hidden" name="items[{{ $i }}][ticket_type_id]" value="{{ $type->id }}">
                                </td>
                                <td class="num">{{ $avail === null ? 'Shared pool' : $avail }}</td>
                                <td>
                                    @if ($avail === null)
                                        <input type="number" name="items[{{ $i }}][quantity]" min="0" value="0">
                                    @else
                                        <input type="number" name="items[{{ $i }}][quantity]" min="0" value="0"
                                               max="{{ max(0, $avail) }}">
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="hint">Set a quantity of 0 for ticket types you don't want to include.</p>
                @error('items') <p class="error">{{ $message }}</p> @enderror

                <div class="form-actions">
                    <button type="submit" class="btn">Issue tickets</button>
                </div>
            </form>
        @endif
    </div>

    @push('scripts')
    <script>
        // Comp issuance: disable zero-quantity rows so only chosen ticket types are
        // submitted (the server requires every submitted item to have quantity >= 1).
        (function () {
            var form = document.getElementById('comp-form');
            if (!form) return;
            form.addEventListener('submit', function () {
                form.querySelectorAll('input[name$="[quantity]"]').forEach(function (qty) {
                    if (parseInt(qty.value, 10) > 0) return;
                    var row = qty.closest('tr');
                    qty.disabled = true;
                    if (row) {
                        var hidden = row.querySelector('input[name$="[ticket_type_id]"]');
                        if (hidden) hidden.disabled = true;
                    }
                });
            });
        })();
    </script>
    @endpush
@endcan
