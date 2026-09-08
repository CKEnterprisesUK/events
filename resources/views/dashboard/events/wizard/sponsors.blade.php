{{--
    Wizard step 6 (final): Sponsors (Req 4.7). Reuses the sponsor fields from the
    dedicated Sponsors screen — logo, name, website, bio, and the "show on
    ticket" flag — inside the wizard's advance form. Gated behind `settings` by
    the controller, so it is only reachable for users who can manage branding.

    A "do you have sponsors?" gate keeps the step optional and unintimidating:
    the fields are revealed only when the organiser opts in. Posts to
    `events.wizard.save` step `sponsors` (multipart). This is the last reachable
    step, so the nav's submit reads "Finish" and the controller redirects to the
    manage screen. Persistence is task 7.3; Skip is available too.
--}}
@extends('dashboard.events.wizard._layout')

@section('wizard_skip', true)

@section('wizard_form_open')
    <form method="POST"
          action="{{ route('dashboard.events.wizard.save', ['event' => $event, 'step' => $currentStep]) }}"
          enctype="multipart/form-data" class="stack">
@endsection

@section('wizard_panel')
    @php
        $existingSponsors = $event->sponsors;
        $imgUrl = fn ($path) => \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    @endphp

    {{-- Context: sponsors already on this draft, if any were added earlier. --}}
    @if ($existingSponsors->isNotEmpty())
        <div class="field">
            <p class="muted" style="margin:0 0 .5rem;">Already added:</p>
            <ul class="sponsor-list sponsor-list--compact">
                @foreach ($existingSponsors as $sponsor)
                    <li class="sponsor-list__item">
                        <div class="sponsor-list__logo">
                            <img src="{{ $imgUrl($sponsor->image_path) }}" alt="{{ $sponsor->name ?: 'Sponsor' }}">
                        </div>
                        <span>{{ $sponsor->name ?: 'Sponsor' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- "Do you have sponsors?" gate — reveals the add fields on opt-in. --}}
    <fieldset class="field" data-sponsor-gate>
        <legend>Do you have a sponsor to add?</legend>
        <label class="choice">
            <input type="radio" name="has_sponsor" value="0" data-sponsor-mode
                   @checked(old('has_sponsor', '0') === '0')>
            Not right now
        </label>
        <label class="choice">
            <input type="radio" name="has_sponsor" value="1" data-sponsor-mode
                   @checked(old('has_sponsor') === '1')>
            Yes, add one
        </label>
    </fieldset>

    <div data-sponsor-fields hidden>
        <div class="field">
            <label for="sponsor-image">Sponsor logo</label>
            <input type="file" id="sponsor-image" name="image" accept="image/*">
            <span class="field-hint">JPEG, PNG, GIF or WebP up to 8&nbsp;MB.</span>
            @error('image') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="sponsor-name">Name <span class="muted">(optional)</span></label>
            <input type="text" id="sponsor-name" name="name" value="{{ old('name') }}" maxlength="255">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="sponsor-website">Website <span class="muted">(optional)</span></label>
            <input type="url" id="sponsor-website" name="website_url" value="{{ old('website_url') }}"
                   placeholder="https://example.com" maxlength="255">
            @error('website_url') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="field">
            <label for="sponsor-bio">Bio <span class="muted">(optional)</span></label>
            <textarea id="sponsor-bio" name="bio" rows="2" maxlength="2000">{{ old('bio') }}</textarea>
            @error('bio') <p class="error">{{ $message }}</p> @enderror
        </div>

        <label class="consent">
            <input type="checkbox" name="on_ticket" value="1" @checked(old('on_ticket'))>
            Show on ticket
        </label>
        @error('on_ticket') <p class="error">{{ $message }}</p> @enderror
    </div>
@endsection

@section('wizard_help')
    <h2 class="wizard-help__title">Add your sponsors</h2>
    <p>Sponsors appear on your public event page, and you can choose to print a few on the ticket itself.</p>
    <p>Have one to add now? Upload their logo — a name, website and short bio are optional. No sponsors? Just leave this and finish.</p>
    <p>You can add, reorder, and manage sponsors any time from the event’s Sponsors screen.</p>
@endsection

@push('scripts')
<script>
    (function () {
        // Reveal the sponsor fields only when "Yes, add one" is selected.
        var gate = document.querySelector('[data-sponsor-gate]');
        var fields = document.querySelector('[data-sponsor-fields]');
        if (!gate || !fields) return;
        function sync() {
            var sel = gate.querySelector('[data-sponsor-mode]:checked');
            fields.hidden = !(sel && sel.value === '1');
        }
        gate.querySelectorAll('[data-sponsor-mode]').forEach(function (r) {
            r.addEventListener('change', sync);
        });
        sync();
    })();
</script>
@endpush
