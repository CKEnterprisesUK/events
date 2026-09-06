{{--
    Shared Event create/edit fields. Pass $event (an Event or null for create).
    Field names match EventController::validated().
--}}
@php $event = $event ?? null; @endphp

<div class="field">
    <label for="name">Event name</label>
    <input id="name" type="text" name="name" required
           value="{{ old('name', $event?->name) }}">
    @error('name') <p class="error">{{ $message }}</p> @enderror
</div>

<div class="field">
    <label for="venue">Venue <span class="muted">(optional)</span></label>
    <input id="venue" type="text" name="venue"
           value="{{ old('venue', $event?->venue) }}">
    @error('venue') <p class="error">{{ $message }}</p> @enderror
</div>

<div class="field-row">
    <div class="field">
        <label for="starts_at">Starts at <span class="muted">(optional)</span></label>
        <input id="starts_at" type="datetime-local" name="starts_at"
               value="{{ old('starts_at', $event?->starts_at?->format('Y-m-d\TH:i')) }}">
        @error('starts_at') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label for="capacity">Overall capacity <span class="muted">(optional)</span></label>
        <input id="capacity" type="number" name="capacity" min="1" placeholder="Unlimited"
               value="{{ old('capacity', $event?->capacity) }}">
        <p class="hint">Leave blank for unlimited.</p>
        @error('capacity') <p class="error">{{ $message }}</p> @enderror
    </div>
</div>

<div class="field">
    <label for="description">Description <span class="muted">(optional)</span></label>
    <textarea id="description" name="description" rows="4">{{ old('description', $event?->description) }}</textarea>
    @error('description') <p class="error">{{ $message }}</p> @enderror
</div>
