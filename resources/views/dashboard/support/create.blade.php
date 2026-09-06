@extends('layouts.dashboard')

@section('title', 'Contact support')

@push('head')
<style>
    .support-grid { display: grid; gap: 1.5rem; max-width: 640px; }
    .support-form { padding: 1.25rem; }
    .support-form .field { margin-bottom: 1.1rem; }
    .support-form label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
    .support-form input[type="text"],
    .support-form select,
    .support-form textarea {
        width: 100%; padding: 0.55rem 0.7rem; border: 1px solid var(--border);
        border-radius: 0.5rem; font: inherit; background: #fff;
    }
    .support-form textarea { min-height: 160px; resize: vertical; line-height: 1.5; }
    .support-form .error { color: #b91c1c; font-size: 0.85rem; margin: 0.25rem 0 0; }
    .support-form .hint { color: var(--muted, #6b7280); font-size: 0.85rem; margin: 0.3rem 0 0; }

    .consent {
        display: flex; gap: 0.7rem; align-items: flex-start;
        padding: 0.9rem 1rem; border: 1px solid var(--border);
        border-radius: 0.6rem; background: rgba(0,0,0,0.015);
    }
    .consent input[type="checkbox"] { margin-top: 0.2rem; width: 1.1rem; height: 1.1rem; flex: 0 0 auto; }
    .consent label { font-weight: 600; margin: 0; }
    .consent .consent-help { display: block; font-weight: 400; margin-top: 0.25rem; color: var(--muted, #6b7280); font-size: 0.85rem; }

    .support-aside p { margin: 0 0 0.6rem; line-height: 1.55; }
    .support-aside p:last-child { margin-bottom: 0; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Contact support</h1>
    </div>

    <div class="support-grid">
        @if (session('status'))
            <p class="status" data-status="saved">{{ session('status') }}</p>
        @endif
        @if (session('support_error'))
            <p class="status" data-status="error">{{ session('support_error') }}</p>
        @endif

        <div class="panel">
            <div class="panel__head">
                <h2>Raise a support request</h2>
                <p class="muted">
                    Not found your answer in
                    <a href="{{ route('dashboard.help.index') }}">Help &amp; knowledge</a>?
                    Tell us what’s going on and the CK Enterprises team will get back
                    to you by email.
                </p>
            </div>

            <form method="POST" action="{{ route('dashboard.support.store') }}" class="support-form">
                @csrf

                <div class="field">
                    <label for="category">What’s this about?</label>
                    <select name="category" id="category" required>
                        @foreach ($categories as $value => $label)
                            <option value="{{ $value }}" @selected(old('category', $selectedCategory) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="subject">Subject</label>
                    <input type="text" name="subject" id="subject" value="{{ old('subject') }}"
                           maxlength="255" required placeholder="A short summary of the issue">
                    @error('subject')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="message">How can we help?</label>
                    <textarea name="message" id="message" required
                              placeholder="Describe what you were doing, what you expected, and what happened. Include any order or event references that might help us.">{{ old('message') }}</textarea>
                    <p class="hint">Please don’t include passwords or full card numbers.</p>
                    @error('message')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <div class="consent">
                        <input type="checkbox" name="access_consent" id="access_consent" value="1" @checked(old('access_consent'))>
                        <label for="access_consent">
                            Allow CK Enterprises to access my account to assist with this request
                            <span class="consent-help">
                                Giving permission lets our support team securely look at your
                                account to investigate and resolve the issue faster. We only do
                                this to help with your request, every access is recorded in your
                                activity log, and you can leave this unticked if you’d rather we
                                didn’t.
                            </span>
                        </label>
                    </div>
                    @error('access_consent')<p class="error">{{ $message }}</p>@enderror
                </div>

                <button type="submit" class="btn">Send request</button>
            </form>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Prefer email?</h2></div>
            <div class="support-form support-aside">
                <p class="muted">
                    You can also reach the CK Enterprises support team directly at
                    <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.
                </p>
                <p class="muted">
                    For faster help, include your company name and any relevant order
                    or event reference.
                </p>
            </div>
        </div>
    </div>
@endsection
