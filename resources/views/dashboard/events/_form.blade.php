{{--
    Core Event details fields for the Overview screen's edit form: name, when,
    capacity, description, hero image. Venue and full location live on the
    dedicated "Where" screen (dashboard/events/location.blade.php), so they are
    intentionally absent here. Field names match EventController::validated().

    IMPORTANT: this partial includes a file upload (the hero/poster image), so
    the enclosing <form> MUST set enctype="multipart/form-data". This partial
    does NOT own the <form> tag — dashboard/events/show.blade.php is responsible
    for setting it.
--}}
@php $event = $event ?? null; @endphp

<div class="field">
    <label for="name">Event name</label>
    <input id="name" type="text" name="name" required
           value="{{ old('name', $event?->name) }}">
    @error('name') <p class="error">{{ $message }}</p> @enderror
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

<div class="field">
    <label for="poster">Hero image <span class="muted">(optional)</span></label>
    @if ($event?->poster_path)
        <div class="poster-preview">
            <img class="poster-thumb"
                 src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($event->poster_path) }}"
                 alt="Current hero image for {{ $event->name }}">
            <p class="hint">Uploading a new image replaces the current one.</p>
        </div>
    @endif
    <input id="poster" type="file" name="poster" accept="image/jpeg,image/png,image/webp">
    <p class="hint">JPEG, PNG or WebP up to 4 MB. Shown at the top of this event's page.</p>
    @error('poster') <p class="error">{{ $message }}</p> @enderror
</div>
