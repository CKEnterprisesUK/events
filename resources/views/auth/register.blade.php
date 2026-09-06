@extends('layouts.app')

@section('title', 'Sign up')

@section('main_class', 'public-main-wide')

@php
    // Fields owned by each wizard step, so a server-side validation error can
    // reveal the step that contains the first invalid field on reload.
    $stepFields = [
        1 => ['company_name', 'slug', 'legal_name', 'trading_name', 'organisation_type', 'company_number', 'charity_number', 'website', 'organisation_email', 'phone'],
        2 => ['address_line_1', 'address_line_2', 'city', 'postcode', 'country'],
        3 => ['name', 'email', 'password'],
    ];

    $errorStep = 1;
    foreach ($stepFields as $step => $fields) {
        foreach ($fields as $f) {
            if ($errors->has($f)) {
                $errorStep = $step;
                break 2;
            }
        }
    }
@endphp

@section('content')
    <section class="auth-card auth-card-wide">
        <h1>Create your account</h1>
        <p class="muted">Set up your organisation and start selling tickets. You'll be the account Owner.</p>

        {{-- Step progress indicator --}}
        <ol class="wizard-steps" aria-label="Signup progress">
            <li class="wizard-step" data-step-indicator="1">
                <span class="wizard-step-num">1</span>
                <span class="wizard-step-label">Organisation</span>
            </li>
            <li class="wizard-step" data-step-indicator="2">
                <span class="wizard-step-num">2</span>
                <span class="wizard-step-label">Address</span>
            </li>
            <li class="wizard-step" data-step-indicator="3">
                <span class="wizard-step-num">3</span>
                <span class="wizard-step-label">Your account</span>
            </li>
        </ol>

        <form method="POST" action="{{ url('/register') }}" id="signup-form" data-error-step="{{ $errorStep }}">
            @csrf

            {{-- ============================ Step 1 ============================ --}}
            <fieldset class="wizard-panel" data-step="1">
                <legend class="wizard-panel-title">Organisation details</legend>

                <div class="field-row">
                    <div class="field">
                        <label for="company_name">Organisation name</label>
                        <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" required autofocus>
                        @error('company_name')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="legal_name">Registered / legal name</label>
                        <input id="legal_name" type="text" name="legal_name" value="{{ old('legal_name') }}" required>
                        @error('legal_name')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="field">
                    <label for="slug">Storefront address</label>
                    <div class="slug-input">
                        <span class="slug-prefix">{{ rtrim(url('/'), '/') }}/</span>
                        <input id="slug" type="text" name="slug" value="{{ old('slug') }}" placeholder="your-org" required
                            pattern="[a-z0-9\-]+" autocomplete="off" spellcheck="false">
                    </div>
                    <p class="hint">Lowercase letters, numbers and hyphens only. This is your public storefront URL.</p>
                    @error('slug')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="trading_name">Trading name <span class="muted">(optional)</span></label>
                        <input id="trading_name" type="text" name="trading_name" value="{{ old('trading_name') }}">
                        <p class="hint">Your public-facing name, if different from the legal name.</p>
                        @error('trading_name')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="organisation_type">Organisation type</label>
                        <select id="organisation_type" name="organisation_type" required data-org-type>
                            <option value="" disabled @selected(old('organisation_type') === null)>Choose type…</option>
                            @foreach (\App\Models\Company::ORGANISATION_TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(old('organisation_type') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('organisation_type')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                {{-- Companies House number: shown only for Company / CIC. --}}
                <div class="field" data-when-type="company cic" hidden>
                    <label for="company_number">Companies House number</label>
                    <input id="company_number" type="text" name="company_number" value="{{ old('company_number') }}" data-conditional-required>
                    <p class="hint">Required for companies and CICs.</p>
                    @error('company_number')<p class="error">{{ $message }}</p>@enderror
                </div>

                {{-- Charity Commission number: shown only for Charity, optional
                     (some charities are not registered with the Commission). --}}
                <div class="field" data-when-type="charity" hidden>
                    <label for="charity_number">Charity Commission number <span class="muted">(if registered)</span></label>
                    <input id="charity_number" type="text" name="charity_number" value="{{ old('charity_number') }}">
                    <p class="hint">Leave blank if your charity isn't registered with the Charity Commission.</p>
                    @error('charity_number')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="organisation_email">Organisation email</label>
                        <input id="organisation_email" type="email" name="organisation_email" value="{{ old('organisation_email') }}" required>
                        <p class="hint">Main contact email for the organisation.</p>
                        @error('organisation_email')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="phone">Phone <span class="muted">(recommended)</span></label>
                        <input id="phone" type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel">
                        @error('phone')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="field">
                    <label for="website">Website <span class="muted">(optional)</span></label>
                    <input id="website" type="url" name="website" value="{{ old('website') }}" placeholder="https://example.org">
                    @error('website')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions wizard-nav">
                    <button type="button" class="btn" data-next>Continue</button>
                </div>
            </fieldset>

            {{-- ============================ Step 2 ============================ --}}
            <fieldset class="wizard-panel" data-step="2" hidden>
                <legend class="wizard-panel-title">Registered address</legend>

                <div class="field">
                    <label for="address_line_1">Address line 1</label>
                    <input id="address_line_1" type="text" name="address_line_1" value="{{ old('address_line_1') }}" required autocomplete="address-line1">
                    @error('address_line_1')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="address_line_2">Address line 2 <span class="muted">(optional)</span></label>
                    <input id="address_line_2" type="text" name="address_line_2" value="{{ old('address_line_2') }}" autocomplete="address-line2">
                    @error('address_line_2')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="city">City</label>
                        <input id="city" type="text" name="city" value="{{ old('city') }}" required autocomplete="address-level2">
                        @error('city')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="postcode">Postcode</label>
                        <input id="postcode" type="text" name="postcode" value="{{ old('postcode') }}" required autocomplete="postal-code">
                        @error('postcode')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="country">Country</label>
                        <input id="country" type="text" name="country" value="{{ old('country', 'GB') }}" maxlength="2" required autocomplete="country"
                            pattern="[A-Za-z]{2}">
                        <p class="hint">Two-letter code (ISO 3166-1). Defaults to GB.</p>
                        @error('country')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="form-actions wizard-nav">
                    <button type="button" class="btn btn-outline" data-prev>Back</button>
                    <button type="button" class="btn" data-next>Continue</button>
                </div>
            </fieldset>

            {{-- ============================ Step 3 ============================ --}}
            <fieldset class="wizard-panel" data-step="3" hidden>
                <legend class="wizard-panel-title">Your account</legend>
                <p class="hint">This is the Owner login for your new account.</p>

                <div class="field-row">
                    <div class="field">
                        <label for="name">Your name</label>
                        <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
                        @error('name')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
                        @error('email')<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password">
                        @error('password')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                    </div>
                </div>

                <div class="form-actions wizard-nav">
                    <button type="button" class="btn btn-outline" data-prev>Back</button>
                    <button type="submit" class="btn">Create account</button>
                </div>
            </fieldset>
        </form>

        <p class="auth-alt">Already have an account? <a href="{{ url('/login') }}">Log in</a></p>
    </section>
@endsection

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('signup-form');
        if (!form) return;

        var panels = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
        var indicators = Array.prototype.slice.call(document.querySelectorAll('[data-step-indicator]'));
        var totalSteps = panels.length;
        var current = parseInt(form.getAttribute('data-error-step'), 10) || 1;

        // ---- Conditional registration-number fields ----
        var orgType = form.querySelector('[data-org-type]');
        var conditionalGroups = Array.prototype.slice.call(form.querySelectorAll('[data-when-type]'));

        function syncConditionalFields() {
            var value = orgType ? orgType.value : '';
            conditionalGroups.forEach(function (group) {
                var allowed = (group.getAttribute('data-when-type') || '').split(/\s+/);
                var show = allowed.indexOf(value) !== -1;
                group.hidden = !show;
                var input = group.querySelector('[data-conditional-required]');
                if (input) {
                    input.required = show;
                    if (!show) { input.value = ''; }
                }
            });
        }

        if (orgType) {
            orgType.addEventListener('change', syncConditionalFields);
            syncConditionalFields();
        }

        // ---- Step navigation ----
        function showStep(step) {
            current = Math.min(Math.max(step, 1), totalSteps);
            panels.forEach(function (panel) {
                panel.hidden = parseInt(panel.getAttribute('data-step'), 10) !== current;
            });
            indicators.forEach(function (ind) {
                var n = parseInt(ind.getAttribute('data-step-indicator'), 10);
                ind.classList.toggle('is-active', n === current);
                ind.classList.toggle('is-complete', n < current);
            });
            var active = panels[current - 1];
            if (active) {
                var focusable = active.querySelector('input:not([type=hidden]), select, textarea');
                if (focusable) { focusable.focus(); }
            }
        }

        // Validate only the fields inside the current step before advancing so
        // the browser surfaces native messages on the right fields.
        function currentPanelValid() {
            var panel = panels[current - 1];
            if (!panel) return true;
            var controls = Array.prototype.slice.call(
                panel.querySelectorAll('input, select, textarea')
            ).filter(function (el) {
                return !el.closest('[hidden]') && !el.disabled;
            });
            for (var i = 0; i < controls.length; i++) {
                if (!controls[i].checkValidity()) {
                    controls[i].reportValidity();
                    return false;
                }
            }
            return true;
        }

        form.addEventListener('click', function (e) {
            var next = e.target.closest('[data-next]');
            var prev = e.target.closest('[data-prev]');
            if (next) {
                e.preventDefault();
                if (currentPanelValid()) { showStep(current + 1); }
            } else if (prev) {
                e.preventDefault();
                showStep(current - 1);
            }
        });

        showStep(current);
    })();
</script>
@endpush
