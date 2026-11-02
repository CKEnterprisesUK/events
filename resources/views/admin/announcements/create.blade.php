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
    .announce-confirm { border: 1px solid #fde68a; background: #fffbeb; color: #92400e; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1.1rem; max-width: 48rem; }
    .announce-confirm label { display: inline-flex; align-items: center; gap: 0.5rem; color: #78350f; font-weight: 600; }

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
    .announce-toolbar .announce-toolbar__spacer { flex: 1; }
    .announce-toolbar select { height: 2rem; padding: 0 0.4rem; border: 1px solid var(--border); border-radius: 0.35rem; background: #fff; font-size: 0.8rem; }

    /* Preview modal. */
    .announce-preview-backdrop {
        position: fixed; inset: 0; background: rgba(17, 24, 39, 0.55);
        display: none; align-items: flex-start; justify-content: center; padding: 2rem 1rem; z-index: 1000; overflow: auto;
    }
    .announce-preview-backdrop.is-open { display: flex; }
    .announce-preview { background: #fff; border-radius: 0.6rem; width: 100%; max-width: 680px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
    .announce-preview__head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
    .announce-preview__head h2 { margin: 0; font-size: 1rem; }
    .announce-preview__close { border: 0; background: transparent; font-size: 1.4rem; line-height: 1; cursor: pointer; color: #6b7280; }
    .announce-preview__frame { width: 100%; height: 460px; border: 0; }
    .announce-preview__note { padding: 0.6rem 1.25rem; font-size: 0.78rem; color: #6b7280; border-top: 1px solid var(--border); }

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
            <form method="POST" action="{{ route('admin.announcements.send') }}" class="announce-form" id="announce-form">
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
                        <span class="announce-toolbar__spacer" aria-hidden="true"></span>
                        <button type="button" id="announce-preview-btn" title="Preview the email before sending">Preview</button>
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

    {{-- Preview modal: shows the composed body rendered into the real email template. --}}
    <div class="announce-preview-backdrop" id="announce-preview-modal" role="dialog" aria-modal="true" aria-labelledby="announce-preview-title">
        <div class="announce-preview">
            <div class="announce-preview__head">
                <h2 id="announce-preview-title">Email preview</h2>
                <button type="button" class="announce-preview__close" id="announce-preview-close" aria-label="Close preview">&times;</button>
            </div>
            <iframe class="announce-preview__frame" id="announce-preview-frame" title="Email preview"></iframe>
            <p class="announce-preview__note">Placeholders are shown with sample values (e.g. the recipient’s name and company). Each recipient gets their own values.</p>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const textarea = document.getElementById('body');
    if (!textarea) return;

    // Wrap the current selection (or the word at the caret) with the given
    // before/after markers; if already wrapped, leave as-is and just re-select.
    function wrapSelection(before, after) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const selected = textarea.value.slice(start, end) || 'text';
        const replacement = before + selected + after;
        textarea.setRangeText(replacement, start, end, 'end');
        // Re-select the inner text so the user can keep typing over it.
        textarea.focus();
        textarea.setSelectionRange(start + before.length, start + before.length + selected.length);
    }

    // Turn each selected line (or the current line) into a list of the given
    // kind. Numbered lists increment; bullets use '- '.
    function listSelection(kind) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;

        // Expand the selection to whole lines.
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        let lineEnd = value.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = value.length;

        const block = value.slice(lineStart, lineEnd);
        const lines = block.split('\n');
        const transformed = lines.map((line, i) => {
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

    // --- Preview ---------------------------------------------------------
    const modal = document.getElementById('announce-preview-modal');
    const frame = document.getElementById('announce-preview-frame');
    const previewBtn = document.getElementById('announce-preview-btn');
    const closeBtn = document.getElementById('announce-preview-close');
    const previewUrl = @json(route('admin.announcements.preview'));
    const csrf = document.querySelector('input[name="_token"]').value;

    function openModal() { modal.classList.add('is-open'); }
    function closeModal() { modal.classList.remove('is-open'); }

    if (previewBtn) {
        previewBtn.addEventListener('click', async function () {
            previewBtn.disabled = true;
            previewBtn.textContent = 'Loading…';
            try {
                const res = await fetch(previewUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        body: textarea.value,
                        subject: (document.getElementById('subject') || {}).value || '',
                    }),
                });
                const data = await res.json();
                // Render the returned email HTML inside the sandboxed iframe.
                frame.srcdoc = data.html || '<p style="font-family:sans-serif;color:#6b7280;padding:1rem;">Nothing to preview yet.</p>';
                openModal();
            } catch (e) {
                frame.srcdoc = '<p style="font-family:sans-serif;color:#b91c1c;padding:1rem;">Could not load the preview. Please try again.</p>';
                openModal();
            } finally {
                previewBtn.disabled = false;
                previewBtn.textContent = 'Preview';
            }
        });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
})();
</script>
@endpush
