@extends('layouts.dashboard')

@section('title', 'Super-Admin — Send announcement')

@push('head')
<style>
    .announce-form .field { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 1.1rem; max-width: 48rem; }
    .announce-form label { font-size: 0.8rem; font-weight: 600; color: #374151; }
    .announce-form input[type="text"], .announce-form textarea, .announce-form select {
        padding: 0.55rem 0.7rem; border: 1px solid var(--border); border-radius: 0.45rem; font-size: 0.95rem; font-family: inherit;
    }
    .announce-form textarea { min-height: 12rem; resize: vertical; }
    .announce-form .field-hint { font-size: 0.78rem; color: #6b7280; }
    .announce-roles { display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; }
    .announce-roles label { display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 500; text-transform: none; letter-spacing: 0; }
    .announce-confirm { border: 1px solid #fde68a; background: #fffbeb; color: #92400e; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.1rem; max-width: 48rem; }
    .announce-confirm label { display: inline-flex; align-items: center; gap: 0.5rem; color: #78350f; font-weight: 600; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Send announcement</h1>
            <p>Email an essential platform message to selected team members across your clients.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p class="status status--error" role="alert">{{ $errors->first() }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.announcements.send') }}" class="announce-form">
                @csrf

                <div class="field">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" value="{{ old('subject') }}" maxlength="200" required>
                </div>

                <div class="field">
                    <label for="body">Message</label>
                    <textarea id="body" name="body" maxlength="20000" required placeholder="Write your message…">{{ old('body') }}</textarea>
                    <span class="field-hint">Markdown is supported (headings, <strong>**bold**</strong>, lists, [links](https://example.com)). Raw HTML is not rendered.</span>
                </div>

                <div class="field">
                    <label>Send to roles</label>
                    <div class="announce-roles">
                        @foreach ($roles as $r)
                            <label>
                                <input type="checkbox" name="roles[]" value="{{ $r }}"
                                    @checked(collect(old('roles', []))->contains($r))>
                                {{ \App\Models\User::roleLabel($r) }}
                            </label>
                        @endforeach
                    </div>
                    <span class="field-hint">Disabled accounts and platform super admins are always excluded.</span>
                </div>

                <div class="field">
                    <label for="company_id">Limit to company (optional)</label>
                    <select id="company_id" name="company_id">
                        <option value="">All companies</option>
                        @foreach ($companies as $c)
                            <option value="{{ $c->id }}" @selected((string) old('company_id') === (string) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <span class="field-hint">Leave as “All companies” to send platform-wide within the chosen roles.</span>
                </div>

                @if (session('confirm_count'))
                    <div class="announce-confirm">
                        <p style="margin:0 0 0.6rem;">
                            This will email <strong>{{ number_format(session('confirm_count')) }}</strong> recipients — above the {{ number_format(\App\Http\Controllers\SuperAdmin\AnnouncementController::CONFIRM_THRESHOLD) }} confirmation threshold.
                        </p>
                        <label>
                            <input type="checkbox" name="confirm" value="1" required>
                            Yes, send to all {{ number_format(session('confirm_count')) }} recipients
                        </label>
                    </div>
                @endif

                <button type="submit" class="btn">
                    {{ session('confirm_count') ? 'Confirm & send' : 'Send announcement' }}
                </button>
            </form>
        </div>
    </div>
@endsection
