@extends('layouts.dashboard')

@section('title', 'Super-Admin — Edit ' . $document->title)

@section('content')
    <div class="admin-head">
        <div>
            <h1>Edit: {{ $document->title }}</h1>
            <p>Public URL: <span class="mono">/trust/{{ $document->slug }}</span> @if ($document->is_published && $document->hasBody())(<a href="{{ route('trust.show', $document->slug) }}" target="_blank" rel="noopener">view</a>)@endif</p>
        </div>
        <div class="admin-head__actions">
            <a class="btn btn-outline" href="{{ route('admin.legal.index') }}">Back</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.legal.update', $document) }}">
                @csrf
                @method('PUT')

                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title', $document->title) }}" required maxlength="255">
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="body">Content</label>
                    <p class="hint">Markdown is supported (headings, lists, links, bold/italic). Raw HTML is escaped.</p>
                    <textarea id="body" name="body" rows="22">{{ old('body', $document->body) }}</textarea>
                    @error('body')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $document->sort_order) }}" min="0" max="65535">
                    @error('sort_order')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field field--checkbox">
                    <label>
                        <input type="hidden" name="is_published" value="0">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $document->is_published) ? 'checked' : '' }}>
                        Published (visible on the public Trust &amp; Legal Centre)
                    </label>
                    @error('is_published')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Save document</button>
                </div>
            </form>
        </div>
    </div>
@endsection
