{{--
    Shared Ticket_Type create/edit fields. Pass $ticketType (a TicketType or
    null for create). Field names match TicketTypeController::validated():
    name, price (decimal), capacity, sale_starts_at, sale_ends_at.
--}}
@php $ticketType = $ticketType ?? null; @endphp

<div class="field-row">
    <div class="field">
        <label>Name</label>
        <input type="text" name="name" required maxlength="100"
               value="{{ old('name', $ticketType?->name) }}">
        @error('name') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label>Price</label>
        <input type="number" name="price" step="0.01" min="0" max="999999.99" required
               value="{{ old('price', $ticketType ? number_format($ticketType->price_minor / 100, 2, '.', '') : '0.00') }}">
        <p class="hint">Enter 0 for a free ticket.</p>
        @error('price') <p class="error">{{ $message }}</p> @enderror
    </div>
    @php $capacityMode = old('capacity_mode', $ticketType?->capacity_mode ?? \App\Models\TicketType::MODE_CAPPED); @endphp
    <div class="field">
        <label>Capacity mode</label>
        <select name="capacity_mode">
            <option value="{{ \App\Models\TicketType::MODE_CAPPED }}" @selected($capacityMode === \App\Models\TicketType::MODE_CAPPED)>Capped (its own limit)</option>
            <option value="{{ \App\Models\TicketType::MODE_SHARED_POOL }}" @selected($capacityMode === \App\Models\TicketType::MODE_SHARED_POOL)>Shared pool (draws from event capacity)</option>
        </select>
        @error('capacity_mode') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div class="field" data-capacity-field
         @if($capacityMode === \App\Models\TicketType::MODE_SHARED_POOL) style="display:none;" @endif>
        <label>Capacity</label>
        <input type="number" name="capacity" min="1" max="1000000"
               @if($capacityMode === \App\Models\TicketType::MODE_CAPPED) required @endif
               value="{{ old('capacity', $ticketType?->capacity) }}">
        <p class="hint">Required for capped types.</p>
        @error('capacity') <p class="error">{{ $message }}</p> @enderror
    </div>
</div>

<div class="field-row">
    <div class="field">
        <label>Sale starts</label>
        <input type="datetime-local" name="sale_starts_at" required
               value="{{ old('sale_starts_at', $ticketType?->sale_starts_at?->format('Y-m-d\TH:i')) }}">
        @error('sale_starts_at') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label>Sale ends</label>
        <input type="datetime-local" name="sale_ends_at" required
               value="{{ old('sale_ends_at', $ticketType?->sale_ends_at?->format('Y-m-d\TH:i')) }}">
        @error('sale_ends_at') <p class="error">{{ $message }}</p> @enderror
    </div>
</div>

@push('scripts')
<script>
(function () {
    var SHARED_POOL = @json(\App\Models\TicketType::MODE_SHARED_POOL);

    function apply(control) {
        var form = control.closest('form');
        var scope = form || document;
        var field = scope.querySelector('[data-capacity-field]');
        if (!field) { return; }
        var input = field.querySelector('input[name="capacity"]');

        if (control.value === SHARED_POOL) {
            field.style.display = 'none';
            if (input) {
                input.required = false;
                // Disabled inputs are not submitted, so the server treats
                // capacity as absent (nullable) for shared-pool types.
                input.disabled = true;
            }
        } else {
            field.style.display = '';
            if (input) {
                input.disabled = false;
                input.required = true;
            }
        }
    }

    var controls = document.querySelectorAll('[name="capacity_mode"]');
    for (var i = 0; i < controls.length; i++) {
        (function (control) {
            control.addEventListener('change', function () { apply(control); });
            apply(control);
        })(controls[i]);
    }
})();
</script>
@endpush
