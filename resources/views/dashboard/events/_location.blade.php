{{--
    Location tab content: mode toggle + address + Leaflet draggable mini-map.

    IMPORTANT: Renders location fields only (no <form> tag of its own). This
    partial MUST be @include'd INSIDE the single event-details <form> in
    show.blade.php (task 9.2) so location_mode/address/latitude/longitude POST
    together with the event's name/capacity/etc. on the same
    dashboard.events.update request. A separate form here would fail the
    'name' required validation on update().

    Expects $event (an Event) in scope. (Requirements 4.1, 4.3, 4.9)
--}}
@php
    $locationMode = old('location_mode', $event->location_mode ?? \App\Models\Event::LOCATION_IN_PERSON);
    $latitude = old('latitude', $event->latitude);
    $longitude = old('longitude', $event->longitude);
@endphp

<div class="panel" data-location-panel>
    <div class="panel__head"><h2>Location</h2></div>

    @if (session('geocode_warning'))
        <p class="status" data-status="warning" role="status">{{ session('geocode_warning') }}</p>
    @endif

    <fieldset class="field">
        <legend>Location type</legend>
        <label class="choice">
            <input type="radio" name="location_mode" value="{{ \App\Models\Event::LOCATION_IN_PERSON }}"
                   data-location-mode
                   @checked($locationMode === \App\Models\Event::LOCATION_IN_PERSON)>
            In person
        </label>
        <label class="choice">
            <input type="radio" name="location_mode" value="{{ \App\Models\Event::LOCATION_ONLINE }}"
                   data-location-mode
                   @checked($locationMode === \App\Models\Event::LOCATION_ONLINE)>
            Online
        </label>
        @error('location_mode') <p class="error">{{ $message }}</p> @enderror
    </fieldset>

    {{-- In-person block: address + draggable mini-map. Shown/hidden by JS
         based on the mode toggle; with no JS both blocks stay visible. --}}
    <div data-location-inperson>
        <div class="field">
            <label for="address">Address</label>
            <textarea id="address" name="address" rows="3"
                      placeholder="Venue address">{{ old('address', $event->address) }}</textarea>
            <p class="hint">We'll try to locate this address on the map. Drag the pin to fine-tune the exact spot.</p>
            @error('address') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <div id="event-map" role="application" tabindex="0"
                 aria-label="Map — drag the pin to set the event location"
                 style="height:320px"></div>
        </div>

        {{-- The mini-map writes coordinates into these hidden inputs, which
             POST with the enclosing event-details form. --}}
        <input type="hidden" name="latitude" id="latitude" value="{{ $latitude }}">
        <input type="hidden" name="longitude" id="longitude" value="{{ $longitude }}">
        @error('latitude') <p class="error">{{ $message }}</p> @enderror
        @error('longitude') <p class="error">{{ $message }}</p> @enderror
    </div>

    {{-- Online note: shown only when online is selected (toggled by JS); with
         no JS it always shows below so the information is never hidden. --}}
    <p class="hint" data-location-online>
        This is an online event. Customers will be told they receive joining details by email.
    </p>
</div>

@push('head')
<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
      crossorigin="">
@endpush

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script>
    (function () {
        // Mode toggle: show/hide the in-person map/address block and the
        // online note. Defensive: only runs when the elements exist.
        var panel = document.querySelector('[data-location-panel]');
        if (panel) {
            var inPerson = panel.querySelector('[data-location-inperson]');
            var online = panel.querySelector('[data-location-online]');
            var radios = panel.querySelectorAll('[data-location-mode]');

            var applyMode = function () {
                var selected = panel.querySelector('[data-location-mode]:checked');
                var value = selected ? selected.value : 'in_person';
                var isOnline = value === 'online';
                if (inPerson) inPerson.hidden = isOnline;
                if (online) online.hidden = !isOnline;
                // A hidden map container has zero size; recompute once shown.
                if (!isOnline && window.__eventMap) {
                    window.setTimeout(function () { window.__eventMap.invalidateSize(); }, 0);
                }
            };

            radios.forEach(function (radio) {
                radio.addEventListener('change', applyMode);
            });
            applyMode();
        }

        // Draggable mini-map.
        var mapEl = document.getElementById('event-map');
        if (!mapEl || !window.L) return;

        var latInput = document.getElementById('latitude');
        var lngInput = document.getElementById('longitude');

        var lat = latInput ? parseFloat(latInput.value) : NaN;
        var lng = lngInput ? parseFloat(lngInput.value) : NaN;

        var hasCoords = !isNaN(lat) && !isNaN(lng);
        var center = hasCoords ? [lat, lng] : [54.5, -3];
        var zoom = hasCoords ? 15 : 5;

        var map = L.map(mapEl).setView(center, zoom);
        window.__eventMap = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        var marker = L.marker(center, { draggable: true }).addTo(map);

        marker.on('dragend', function () {
            var pos = marker.getLatLng();
            if (latInput) latInput.value = pos.lat.toFixed(7);
            if (lngInput) lngInput.value = pos.lng.toFixed(7);
        });

        // Keep the map sized correctly once it becomes visible.
        window.setTimeout(function () { map.invalidateSize(); }, 0);
    })();
</script>
@endpush
