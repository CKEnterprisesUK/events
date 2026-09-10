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
    @include('dashboard.events._starts_at_field', ['event' => null, 'required' => false])
    <div class="field">
        <label for="venue">Venue <span class="muted">(optional)</span></label>
        <input id="venue" type="text" name="venue"
               value="{{ old('venue') }}">
        @error('venue') <p class="error">{{ $message }}</p> @enderror
    </div>
</div>
