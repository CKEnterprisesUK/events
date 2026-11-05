@extends('layouts.dashboard')

@section('title', 'Super-Admin — Client emails')

@push('head')
<style>
    .tmpl-picker { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 0.9rem; max-width: 48rem; margin-bottom: 1.5rem; }
    .tmpl-card { display: block; border: 1px solid var(--border); border-radius: 0.6rem; padding: 1rem 1.1rem; text-decoration: none; color: inherit; background: #fff; }
    .tmpl-card:hover { border-color: #9ca3af; }
    .tmpl-card.is-active { border-color: #111827; box-shadow: 0 0 0 1px #111827 inset; }
    .tmpl-card h3 { margin: 0 0 0.3rem; font-size: 0.95rem; }
    .tmpl-card p { margin: 0; font-size: 0.82rem; color: #6b7280; }

    .announce-form .field { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 1.1rem; max-width: 48rem; }
    .announce-form label { font-size: 0.8rem; font-weight: 600; color: #374151; }
    .announce-form input[type="text"], .announce-form textarea, .announce-form select {
        padding: 0.55rem 0.7rem; border: 1px solid var(--border); border-radius: 0.45rem; font-size: 0.95rem; font-family: inherit;
    }
    .announce-form textarea { min-height: 16rem; resize: vertical; }
    .announce-form .field-hint { font-size: 0.78rem; color: #6b7280; }
    .announce-roles { display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; }
    .announce-roles label { display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 500; text-transform: none; letter-spacing: 0; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Client emails</h1>
            <p>Send a prebuilt email to selected client team members. You can edit the text before it goes out.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <p class="status status--error" role="alert">{{ $errors->first() }}</p>
    @endif

    {{-- Step 0: choose a template. Selecting one reloads the page with the
         template's subject + body pre-filled into the compose form below. --}}
    <div class="tmpl-picker" role="list" aria-label="Email templates">
        @foreach ($templates as $key => $meta)
            <a role="listitem"
               class="tmpl-card {{ $selectedTemplate === $key ? 'is-active' : '' }}"
               href="{{ route('admin.templated-emails.create', ['template' => $key]) }}">
                <h3>{{ $meta['label'] }}</h3>
                <p>{{ $meta['blurb'] }}</p>
            </a>
        @endforeach
    </div>

    @if ($selectedTemplate === null)
        <p class="field-hint" style="max-width:48rem;">Pick a template above to get started.</p>
    @else
        <div class="admin-panel">
            <div class="admin-panel__body">

                {{-- Sales report needs an event. Changing it reloads with the
                     report regenerated from that event's live figures. --}}
                @if ($selectedTemplate === \App\Services\TemplatedEmailLibrary::SALES_REPORT)
                    <form method="GET" action="{{ route('admin.templated-emails.create') }}" class="announce-form" style="margin-bottom:1.5rem;">
                        <input type="hidden" name="template" value="{{ $selectedTemplate }}">
                        <div class="field">
                            <label for="event_picker">Event</label>
                            <select id="event_picker" name="event_id" onchange="this.form.submit()">
                                <option value="">Select an event…</option>
                                @foreach ($events as $ev)
                                    <option value="{{ $ev->id }}" @selected((int) old('event_id', optional($event)->id) === (int) $ev->id)>
                                        {{ $ev->name }}
                                        @if ($ev->company) — {{ $ev->company->name }} @endif
                                        @if ($ev->starts_at) ({{ $ev->starts_at->format('j M Y') }}) @endif
                                    </option>
                                @endforeach
                            </select>
                            <span class="field-hint">The sales figures are pulled live from this event when you pick it.</span>
                        </div>
                    </form>

                    @if ($event === null)
                        <p class="field-hint">Choose an event above to build its sales report.</p>
                    @endif
                @endif

                @php
                    $showForm = $selectedTemplate !== \App\Services\TemplatedEmailLibrary::SALES_REPORT || $event !== null;
                @endphp

                @if ($showForm)
                    {{-- Compose step: posts to REVIEW, never straight to send. --}}
                    <form method="POST" action="{{ route('admin.templated-emails.review') }}" class="announce-form">
                        @csrf
                        <input type="hidden" name="template" value="{{ $selectedTemplate }}">
                        @if ($event !== null)
                            <input type="hidden" name="event_id" value="{{ $event->id }}">
                        @endif

                        <div class="field">
                            <label for="subject">Subject</label>
                            <input type="text" id="subject" name="subject" maxlength="200" required
                                value="{{ old('subject', $defaultSubject) }}">
                        </div>

                        <div class="field">
                            <label for="body">Message</label>
                            <textarea id="body" name="body" maxlength="20000" required>{{ old('body', $defaultBody) }}</textarea>
                            <span class="field-hint">
                                Prefilled from the template — edit freely. Markdown is supported; raw HTML is not rendered.
                                Personalise with <code>@{{name}}</code>, <code>@{{company}}</code>, <code>@{{platform}}</code>, <code>@{{dashboard_url}}</code>.
                            </span>
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
                            <span class="field-hint">Disabled accounts and platform super admins are always excluded. A copy of every send goes to you.</span>
                        </div>

                        <div class="field">
                            <label for="company_id">Limit to company (optional)</label>
                            <select id="company_id" name="company_id">
                                <option value="">All companies</option>
                                @foreach ($companies as $c)
                                    <option value="{{ $c->id }}" @selected((string) old('company_id', $defaultCompanyId) === (string) $c->id)>{{ $c->name }}</option>
                                @endforeach
                            </select>
                            <span class="field-hint">
                                @if ($selectedTemplate === \App\Services\TemplatedEmailLibrary::SALES_REPORT)
                                    Defaults to the event's own company — the usual audience for a sales report.
                                @else
                                    Leave as “All companies” to send platform-wide within the chosen roles.
                                @endif
                            </span>
                        </div>

                        <button type="submit" class="btn">Preview &amp; continue</button>
                        <span class="field-hint" style="margin-left:0.6rem;">You’ll review the rendered email before it sends.</span>
                    </form>
                @endif
            </div>
        </div>
    @endif
@endsection
