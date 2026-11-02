@extends('layouts.dashboard')

@section('title', 'Super-Admin — Review announcement')

@push('head')
<style>
    .review-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; max-width: 760px; }
    .review-summary { border: 1px solid var(--border); border-radius: 0.6rem; background: #f9fafb; padding: 1rem 1.25rem; }
    .review-summary h2 { margin: 0 0 0.75rem; font-size: 0.95rem; }
    .review-summary dl { display: grid; grid-template-columns: 9rem 1fr; gap: 0.4rem 1rem; margin: 0; font-size: 0.9rem; }
    .review-summary dt { color: #6b7280; font-weight: 600; }
    .review-summary dd { margin: 0; color: #111827; }
    .review-count { font-weight: 700; }

    .review-preview { border: 1px solid var(--border); border-radius: 0.6rem; overflow: hidden; }
    .review-preview__head { padding: 0.65rem 1rem; border-bottom: 1px solid var(--border); background: #fff; font-size: 0.85rem; font-weight: 600; color: #374151; }
    .review-preview__frame { display: block; width: 100%; height: 520px; border: 0; background: #f3f4f6; }
    .review-preview__note { padding: 0.55rem 1rem; font-size: 0.78rem; color: #6b7280; border-top: 1px solid var(--border); background: #fff; }

    .review-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
    .review-actions .btn--ghost { background: transparent; border: 1px solid var(--border); color: #374151; }
    .review-ack { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: #374151; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Review announcement</h1>
            <p>Check the email exactly as recipients will see it, then confirm to send.</p>
        </div>
    </div>

    <div class="review-grid">
        <section class="review-summary" aria-label="Send summary">
            <h2>Who this goes to</h2>
            <dl>
                <dt>Subject</dt>
                <dd>{{ $subject }}</dd>

                <dt>Roles</dt>
                <dd>{{ collect($roles)->map(fn ($r) => \App\Models\User::roleLabel($r))->implode(', ') }}</dd>

                <dt>Company scope</dt>
                <dd>{{ $companyName ?? 'All companies' }}</dd>

                <dt>Recipients</dt>
                <dd><span class="review-count">{{ number_format($recipientCount) }}</span> active user{{ $recipientCount === 1 ? '' : 's' }} (plus a copy to you)</dd>
            </dl>
        </section>

        <section class="review-preview" aria-label="Email preview">
            <div class="review-preview__head">Email preview</div>
            {{-- The rendered email HTML is shown inside a sandboxed iframe. --}}
            <iframe
                class="review-preview__frame"
                title="Rendered announcement email"
                sandbox=""
                srcdoc="{{ $previewHtml }}"></iframe>
            <p class="review-preview__note">
                Placeholders show sample values (e.g. <code>{{ '{{name}}' }}</code> as “Alex Taylor”). Each recipient receives their own name and company.
            </p>
        </section>

        <div class="review-actions">
            {{-- Confirm & send: carries the reviewed content back, with the
                 `reviewed` acknowledgement that the send step requires. --}}
            <form method="POST" action="{{ route('admin.announcements.send') }}">
                @csrf
                <input type="hidden" name="subject" value="{{ $subject }}">
                <input type="hidden" name="body" value="{{ $body }}">
                @foreach ($roles as $r)
                    <input type="hidden" name="roles[]" value="{{ $r }}">
                @endforeach
                @if ($companyId !== null)
                    <input type="hidden" name="company_id" value="{{ $companyId }}">
                @endif
                <input type="hidden" name="reviewed" value="1">

                <button type="submit" class="btn">Confirm &amp; send to {{ number_format($recipientCount) }}</button>
            </form>

            {{-- Back to edit re-opens compose with every field preserved (the
                 review step flashes the input straight back). --}}
            <form method="POST" action="{{ route('admin.announcements.edit') }}">
                @csrf
                <input type="hidden" name="subject" value="{{ $subject }}">
                <input type="hidden" name="body" value="{{ $body }}">
                @foreach ($roles as $r)
                    <input type="hidden" name="roles[]" value="{{ $r }}">
                @endforeach
                @if ($companyId !== null)
                    <input type="hidden" name="company_id" value="{{ $companyId }}">
                @endif
                <button type="submit" class="btn btn--ghost" formnovalidate>Back to edit</button>
            </form>
        </div>
    </div>
@endsection
