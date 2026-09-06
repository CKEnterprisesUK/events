{{--
    Minimal Event create fields. Deliberately a subset of _form.blade.php so the
    "New event" flow asks for only the essentials — name, when, and (optionally)
    venue. Everything else (description, hero image, capacity, location, ticket
    types) is edited on the manage page afterwards, where the setup checklist
    guides the manager toward publish-readiness.

    Field names match EventController::validated(). No file upload here, so the
    enclosing <form> does NOT need enctype="multipart/form-data".
--}}
<div class="field">
    <label for="name">Event name</label>
    <input id="name" type="text" name="name" required
           value="{{ old('name') }}">
    @error('name') <p class="error">{{ $message }}</p> @enderror
</div>

<div class="field-row">
    <div class="field">
        <label for="starts_at">Starts at <span class="muted">(optional)</span></label>
        <input id="starts_at" type="datetime-local" name="starts_at" min="{{ now()->format('Y-m-d\TH:i') }}"
               value="{{ old('starts_at') }}">
        <span class="field-hint">The event can’t start in the past.</span>
        @error('starts_at') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div class="field">
        <label for="venue">Venue <span class="muted">(optional)</span></label>
        <input id="venue" type="text" name="venue"
               value="{{ old('venue') }}">
        @error('venue') <p class="error">{{ $message }}</p> @enderror
    </div>
</div>
