{{--
    Wizard step 5: Branding (Req 4.6). Header (hero) image + logo choice — use a
    dedicated event logo or fall back to the account default. Mirrors the fields
    on the dedicated Branding screen (dashboard/branding/event.blade.php) so the
    wizard collects the same overrides. Gated behind `settings` by the
    controller, so this step is only reachable for users who can manage branding.

    Posts to `events.wizard.save` step `branding` with a multipart form (file
    uploads). Optional step → Skip. Persistence is task 7.3.
--}}
@extends('dashboard.events.wizard._layout')

@section('wizard_skip', true)

@section('wizard_form_open')
    <form method="POST"
          action="{{ route('dashboard.events.wizard.save', ['event' => $event, 'step' => $currentStep]) }}"
          enctype="multipart/form-data" class="stack">
@endsection

@section('wizard_panel')
    <div class="field">
        <label for="poster">Header image <span class="muted">(optional)</span></label>
        @if ($event->poster_path)
            <div class="poster-preview">
                <img class="poster-thumb"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($event->poster_path) }}"
                     alt="Current header image for {{ $event->name }}">
                <p class="hint">Uploading a new image replaces the current one.</p>
            </div>
        @endif
        <input id="poster" type="file" name="poster" accept="image/jpeg,image/png,image/webp">
        <p class="hint">A wide hero image shown at the top of your event page. JPEG, PNG or WebP.</p>
        @error('poster') <p class="error">{{ $message }}</p> @enderror
    </div>

    {{-- Logo choice: use the account default, or upload a logo just for this
         event. The radio governs whether the file input is used. --}}
    <fieldset class="field" data-logo-choice>
        <legend>Logo</legend>
        <label class="choice">
            <input type="radio" name="logo_choice" value="account" data-logo-mode
                   @checked(old('logo_choice', $event->logo_path ? 'custom' : 'account') === 'account')>
            Use my account default logo
        </label>
        <label class="choice">
            <input type="radio" name="logo_choice" value="custom" data-logo-mode
                   @checked(old('logo_choice', $event->logo_path ? 'custom' : 'account') === 'custom')>
            Use a different logo for this event
        </label>
        @error('logo_choice') <p class="error">{{ $message }}</p> @enderror
    </fieldset>

    <div class="field" data-logo-custom>
        <label for="logo">Event logo</label>
        @if ($event->logo_path)
            <div class="poster-preview">
                <img class="poster-thumb"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($event->logo_path) }}"
                     alt="Current logo for {{ $event->name }}">
            </div>
        @endif
        <input id="logo" type="file" name="logo" accept="image/*">
        <span class="field-hint">Overrides the account logo for this event only.</span>
        @error('logo') <p class="error">{{ $message }}</p> @enderror
    </div>
@endsection

@section('wizard_help')
    <h2 class="wizard-help__title">Make it yours</h2>
    <p>Add a header image to give your event page a strong first impression. A wide image (roughly 3:1) works best.</p>
    <p>By default your event uses your <strong>account logo</strong>. Choose “different logo” to upload one just for this event — handy for co-branded or one-off events.</p>
    <p>Everything here is optional and easy to change later.</p>
@endsection

@push('scripts')
<script>
    (function () {
        // Show the custom-logo upload only when "different logo" is selected.
        // Progressive enhancement: with no JS both stay visible.
        var choice = document.querySelector('[data-logo-choice]');
        var custom = document.querySelector('[data-logo-custom]');
        if (!choice || !custom) return;
        function sync() {
            var sel = choice.querySelector('[data-logo-mode]:checked');
            custom.hidden = !(sel && sel.value === 'custom');
        }
        choice.querySelectorAll('[data-logo-mode]').forEach(function (r) {
            r.addEventListener('change', sync);
        });
        sync();
    })();
</script>
@endpush
