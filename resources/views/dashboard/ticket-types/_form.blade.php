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
    <div class="field">
        <label>Capacity</label>
        <input type="number" name="capacity" min="1" max="1000000" required
               value="{{ old('capacity', $ticketType?->capacity) }}">
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
