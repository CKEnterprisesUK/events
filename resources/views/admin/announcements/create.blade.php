@extends('layouts.dashboard')

@section('title', 'Super-Admin — Send announcement')

@push('head')
<style>
    .announce-form .field { display: flex; flex-direction: column; gap: 0.3rem; margin-bottom: 1.1rem; max-width: 48rem; }
    .announce-form label { font-size: 0.8rem; font-weight: 600; color: #374151; }
    .announce-form input[type="text"], .announce-form textarea, .announce-form select {
        padding: 0.55rem 0.7rem; border: 1px solid var(--border); border-radius: 0.45rem; font-size: 0.95rem; font-family: inherit;
    }
    .announce-form textarea { min-height: 12rem; resize: vertical; border-top-left-radius: 0; border-top-right-radius: 0; }
    .announce-form .field-hint { font-size: 0.78rem; color: #6b7280; }
    .announce-roles { display: flex; flex-wrap: wrap; gap: 0.5rem 1.25rem; }
    .announce-roles label { display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 500; text-transform: none; letter-spacing: 0; }

    /* Formatting toolbar sitting directly on top of the message textarea. */
    .announce-toolbar {
        display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem;
        padding: 0.4rem 0.5rem; border: 1px solid var(--border); border-bottom: 0;
        border-top-left-radius: 0.45rem; border-top-right-radius: 0.45rem; background: #f9fafb;
    }
    .announce-toolbar button {
        display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; height: 2rem;
        padding: 0 0.45rem; border: 1px solid transparent; border-radius: 0.35rem; background: transparent;
        font-size: 0.9rem; color: #374151; cursor: pointer;
    }
    .announce-toolbar button:hover { background: #e5e7eb; border-color: var(--border); }
    .announce-toolbar button b { font-family: Georgia, 'Times New Roman', serif; }
    .announce-toolbar .announce-toolbar__sep { width: 1px; height: 1.3rem; background: var(--border); margin: 0 0.35rem; }
    .announce-toolbar select { height: 2rem; padding: 0 0.4rem; border: 1px solid var(--border); border-radius: 0.35rem; background: #fff; font-size: 0.8rem; }

    .announce-form .sr-only {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
    }
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
            {{-- Compose step: this form posts to the REVIEW step, never straight to send. --}}
            <form method="POST" action="{{ route('admin.announcements.review') }}" class="announce-form" id="announce-form">
                @csrf

                <div class="field">
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject" value="{{ old('subject') }}" maxlength="200" required>
                </div>

                <div class="field">
                    <label for="body">Message</label>

                    <div class="announce-toolbar" role="toolbar" aria-label="Formatting" aria-controls="body">
                        <button type="button" data-md="bold" title="Bold (wrap selection in **)" aria-label="Bold"><b>B</b></button>
                        <button type="button" data-md="italic" title="Italic (wrap selection in *)" aria-label="Italic"><i>I</i></button>
                        <span class="announce-toolbar__sep" aria-hidden="true"></span>
                        <button type="button" data-md="ul" title="Bullet list" aria-label="Bullet list">&bull; List</button>
                        <button type="button" data-md="ol" title="Numbered list" aria-label="Numbered list">1. List</button>
                        <span class="announce-toolbar__sep" aria-hidden="true"></span>
                        <label for="announce-placeholder" class="sr-only">Insert placeholder</label>
                        <select id="announce-placeholder" title="Insert a personalisation placeholder">
                            <option value="">Insert field…</option>
                            @foreach ($placeholders as $token => $desc)
                                <option value="{{ $token }}">{{ $desc }} ({{ $token }})</option>
                            @endforeach
                        </select>
                    </div>

                    <textarea id="body" name="body" maxlength="20000" required placeholder="Write your message…">{{ old('body') }}</textarea>
                    <span class="field-hint">
                        Markdown is supported (headings, <strong>**bold**</strong>, lists, [links](https://example.com)). Raw HTML is not rendered.
                        Personalise with placeholders:
                        @foreach ($placeholders as $token => $desc)<code>{{ $token }}</code>@if (! $loop->last), @endif @endforeach.
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
                            <option value="{{ $c->id }}" @selected((string) old('company_id') === (string) $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <span class="field-hint">Leave as “All companies” to send platform-wide within the chosen roles.</span>
                </div>

                <button type="submit" class="btn">Preview &amp; continue</button>
                <span class="field-hint" style="margin-left:0.6rem;">You’ll review the rendered email before it sends.</span>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const textarea = document.getElementById('body');
    if (!textarea) return;

    // Wrap the current selection (or a placeholder word) with the given markers.
    function wrapSelection(before, after) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.slice(start, end) || 'text';
        textarea.setRangeText(before + selected + after, start, end, 'end');
        textarea.focus();
        textarea.setSelectionRange(start + before.length, start + before.length + selected.length);
    }

    // Turn each selected line (or the current line) into a list of the given kind.
    function listSelection(kind) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        let lineEnd = value.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = value.length;

        const lines = value.slice(lineStart, lineEnd).split('\n');
        const transformed = lines.map(function (line, i) {
            const text = line.replace(/^(\s*([-*]|\d+\.)\s+)/, '');
            return kind === 'ol' ? (i + 1) + '. ' + text : '- ' + text;
        }).join('\n');

        textarea.setRangeText(transformed, lineStart, lineEnd, 'end');
        textarea.focus();
        textarea.setSelectionRange(lineStart, lineStart + transformed.length);
    }

    function insertAtCaret(text) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        textarea.setRangeText(text, start, end, 'end');
        textarea.focus();
    }

    document.querySelectorAll('.announce-toolbar button[data-md]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const kind = btn.getAttribute('data-md');
            if (kind === 'bold') wrapSelection('**', '**');
            else if (kind === 'italic') wrapSelection('*', '*');
            else if (kind === 'ul') listSelection('ul');
            else if (kind === 'ol') listSelection('ol');
        });
    });

    const placeholderSelect = document.getElementById('announce-placeholder');
    if (placeholderSelect) {
        placeholderSelect.addEventListener('change', function () {
            if (placeholderSelect.value) {
                insertAtCaret(placeholderSelect.value);
                placeholderSelect.value = '';
            }
        });
    }
})();
</script>
@endpush
