@extends('layouts.dashboard')

@section('title', 'Branding')

@section('content')
    <section>
        <h1>Branding</h1>

        @if (session('status'))
            <p class="status" data-status="saved">{{ session('status') }}</p>
        @endif

        {{-- Effective Company branding preview: logo (7.1), primary colour (7.2). --}}
        <div class="branding-preview" @if ($branding->hasPrimaryColour()) style="--brand: {{ $branding->primaryColour }}" @endif>
            @if ($branding->hasLogo())
                <img class="brand-logo" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}" alt="Company logo">
            @else
                <p>No logo uploaded.</p>
            @endif

            @if ($branding->hasPrimaryColour())
                <p data-primary-colour="{{ $branding->primaryColour }}">Primary colour: {{ $branding->primaryColour }}</p>
            @endif
        </div>

        <form method="POST" action="{{ route('dashboard.branding.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            {{-- Logo upload — stored and displayed on Storefront/tickets. (7.1) --}}
            <div class="field">
                <label for="logo">Logo</label>
                <input type="file" name="logo" id="logo" accept="image/*">
                @error('logo')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Primary brand colour applied to the Storefront. (7.2) --}}
            <div class="field">
                <label for="primary_colour">Primary colour</label>
                <input type="text" name="primary_colour" id="primary_colour"
                       value="{{ old('primary_colour', $company->primary_colour) }}" placeholder="#2563eb">
                @error('primary_colour')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Terms & Conditions shown at checkout. (7.3) --}}
            <div class="field">
                <label for="terms_text">Terms &amp; Conditions</label>
                <textarea name="terms_text" id="terms_text" rows="6">{{ old('terms_text', $company->terms_text) }}</textarea>
                @error('terms_text')<p class="error">{{ $message }}</p>@enderror
            </div>

            {{-- Legal/registration details captured at signup and maintained
                 here by the Owner. --}}
            <fieldset class="field">
                <legend>Organisation details</legend>

                <div class="field">
                    <label for="legal_name">Registered / legal name</label>
                    <input type="text" name="legal_name" id="legal_name"
                           value="{{ old('legal_name', $company->legal_name) }}" required>
                    @error('legal_name')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="trading_name">Trading name</label>
                    <input type="text" name="trading_name" id="trading_name"
                           value="{{ old('trading_name', $company->trading_name) }}">
                    <span class="field-hint">Your public-facing name, if different from the legal name.</span>
                    @error('trading_name')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="organisation_type">Organisation type</label>
                    <select name="organisation_type" id="organisation_type" required>
                        @foreach (\App\Models\Company::ORGANISATION_TYPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('organisation_type', $company->organisation_type) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('organisation_type')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="company_number">Companies House number</label>
                    <input type="text" name="company_number" id="company_number"
                           value="{{ old('company_number', $company->company_number) }}">
                    <span class="field-hint">Required for companies and CICs.</span>
                    @error('company_number')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="charity_number">Charity Commission number</label>
                    <input type="text" name="charity_number" id="charity_number"
                           value="{{ old('charity_number', $company->charity_number) }}">
                    <span class="field-hint">Required for charities.</span>
                    @error('charity_number')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="website">Website</label>
                    <input type="url" name="website" id="website"
                           value="{{ old('website', $company->website) }}" placeholder="https://example.org">
                    @error('website')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="email">Organisation email</label>
                    <input type="email" name="email" id="email"
                           value="{{ old('email', $company->email) }}" required>
                    <span class="field-hint">Main contact email for the organisation.</span>
                    @error('email')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="phone">Phone</label>
                    <input type="tel" name="phone" id="phone"
                           value="{{ old('phone', $company->phone) }}">
                    @error('phone')<p class="error">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            {{-- Registered / business address. --}}
            <fieldset class="field">
                <legend>Registered address</legend>

                <div class="field">
                    <label for="address_line_1">Address line 1</label>
                    <input type="text" name="address_line_1" id="address_line_1"
                           value="{{ old('address_line_1', $company->address_line_1) }}" required>
                    @error('address_line_1')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="address_line_2">Address line 2</label>
                    <input type="text" name="address_line_2" id="address_line_2"
                           value="{{ old('address_line_2', $company->address_line_2) }}">
                    @error('address_line_2')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="city">City</label>
                    <input type="text" name="city" id="city"
                           value="{{ old('city', $company->city) }}" required>
                    @error('city')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="postcode">Postcode</label>
                    <input type="text" name="postcode" id="postcode"
                           value="{{ old('postcode', $company->postcode) }}" required>
                    @error('postcode')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="country">Country</label>
                    <input type="text" name="country" id="country" maxlength="2"
                           value="{{ old('country', $company->country) }}" required pattern="[A-Za-z]{2}">
                    <span class="field-hint">Two-letter country code (ISO 3166-1).</span>
                    @error('country')<p class="error">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            {{-- Contact addresses: public support contact for Customers, and the
                 GDPR/data-protection contact for data-subject requests. --}}
            <fieldset class="field">
                <legend>Contact details</legend>

                <div class="field">
                    <label for="support_email">Support contact email</label>
                    <input type="email" name="support_email" id="support_email"
                           value="{{ old('support_email', $company->support_email) }}" placeholder="support@yourcompany.com">
                    <span class="field-hint">Shown to customers who need help with their order.</span>
                    @error('support_email')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="gdpr_contact_email">GDPR / data-protection contact email</label>
                    <input type="email" name="gdpr_contact_email" id="gdpr_contact_email"
                           value="{{ old('gdpr_contact_email', $company->gdpr_contact_email) }}" placeholder="privacy@yourcompany.com">
                    <span class="field-hint">Where data-subject and privacy requests are handled.</span>
                    @error('gdpr_contact_email')<p class="error">{{ $message }}</p>@enderror
                </div>
            </fieldset>

            {{-- Custom ticket information fields printed on tickets. (7.4) --}}
            <fieldset class="field">
                <legend>Custom ticket fields</legend>
                @php($fields = $branding->ticketFieldDefs)
                @for ($i = 0; $i < 5; $i++)
                    <input type="text" name="ticket_field_defs[]"
                           value="{{ $fields[$i]['label'] ?? '' }}" placeholder="Field label">
                @endfor
                @error('ticket_field_defs.*')<p class="error">{{ $message }}</p>@enderror
            </fieldset>

            <button type="submit" class="btn">Save branding</button>
        </form>
    </section>
@endsection
