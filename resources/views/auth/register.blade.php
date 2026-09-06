@extends('layouts.app')

@section('title', 'Sign up')

@section('content')
    <section class="auth-card">
        <h1>Create your account</h1>
        <p class="muted">Set up your organisation and start selling tickets. You'll be the account Owner.</p>

        <form method="POST" action="{{ url('/register') }}">
            @csrf

            <div class="field">
                <label for="company_name">Organisation name</label>
                <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}" required autofocus>
                @error('company_name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="slug">Storefront address</label>
                <div class="slug-input">
                    <span class="slug-prefix">{{ rtrim(url('/'), '/') }}/</span>
                    <input id="slug" type="text" name="slug" value="{{ old('slug') }}" placeholder="your-org" required
                        pattern="[a-z0-9\-]+" autocomplete="off" spellcheck="false">
                </div>
                <p class="hint">Lowercase letters, numbers and hyphens only. This is your public storefront URL.</p>
                @error('slug')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <hr class="divider">

            <fieldset class="field">
                <legend>Organisation details</legend>
                <p class="hint">We collect these to identify and, where needed, verify your organisation.</p>

                <div class="field">
                    <label for="legal_name">Registered / legal name</label>
                    <input id="legal_name" type="text" name="legal_name" value="{{ old('legal_name') }}" required>
                    @error('legal_name')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="trading_name">Trading name <span class="muted">(optional)</span></label>
                    <input id="trading_name" type="text" name="trading_name" value="{{ old('trading_name') }}">
                    <p class="hint">Your public-facing name, if different from the legal name.</p>
                    @error('trading_name')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="organisation_type">Organisation type</label>
                    <select id="organisation_type" name="organisation_type" required>
                        <option value="" disabled @selected(old('organisation_type') === null)>Choose type…</option>
                        @foreach (\App\Models\Company::ORGANISATION_TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('organisation_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('organisation_type')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="company_number">Companies House number <span class="muted">(if applicable)</span></label>
                    <input id="company_number" type="text" name="company_number" value="{{ old('company_number') }}">
                    <p class="hint">Required for companies and CICs.</p>
                    @error('company_number')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="charity_number">Charity Commission number <span class="muted">(if applicable)</span></label>
                    <input id="charity_number" type="text" name="charity_number" value="{{ old('charity_number') }}">
                    <p class="hint">Required for charities.</p>
                    @error('charity_number')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="website">Website <span class="muted">(optional)</span></label>
                    <input id="website" type="url" name="website" value="{{ old('website') }}" placeholder="https://example.org">
                    @error('website')<p class="error">{{ $message }}</p>@enderror
                </div>

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
            </fieldset>

            <fieldset class="field">
                <legend>Registered address</legend>

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
                    <p class="hint">Two-letter country code (ISO 3166-1). Defaults to GB.</p>
                    @error('country')<p class="error">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            <hr class="divider">

            <div class="field">
                <label for="name">Your name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autocomplete="name">
                @error('name')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username">
                @error('email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-block">Create account</button>
        </form>

        <p class="auth-alt">Already have an account? <a href="{{ url('/login') }}">Log in</a></p>
    </section>
@endsection

@push('scripts')
<script>
    // Gently suggest a slug from the organisation name until the user edits it.
    (function () {
        var nameInput = document.getElementById('company_name');
        var slugInput = document.getElementById('slug');
        if (!nameInput || !slugInput) return;

        var slugEdited = slugInput.value.length > 0;
        slugInput.addEventListener('input', function () { slugEdited = true; });

        function slugify(value) {
            return value.toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/[\s_]+/g, '-')
                .replace(/-+/g, '-');
        }

        nameInput.addEventListener('input', function () {
            if (!slugEdited) {
                slugInput.value = slugify(nameInput.value);
            }
        });
    })();
</script>
@endpush
