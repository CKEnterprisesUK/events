@extends('layouts.dashboard')

@section('title', 'Super-Admin — Edit ' . $article->title)

@section('content')
    <div class="admin-head">
        <div>
            <h1>Edit: {{ $article->title }}</h1>
            <p>
                In <strong>{{ $category->title }}</strong>.
                Public URL: <span class="mono">/help/{{ $category->slug }}/{{ $article->slug }}</span>
                @if ($article->isLive())
                    (<a href="{{ route('help.article', [$category->slug, $article->slug]) }}" target="_blank" rel="noopener">view</a>)
                @endif
            </p>
        </div>
        <div class="admin-head__actions">
            <a class="btn btn-outline" href="{{ route('admin.help.show', $category) }}">Back</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.help.articles.update', [$category, $article]) }}">
                @csrf
                @method('PUT')

                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title', $article->title) }}" required maxlength="255">
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="body">Content</label>
                    <p class="hint">Markdown is supported (headings, lists, links, bold/italic). Raw HTML is escaped.</p>
                    <textarea id="body" name="body" rows="22">{{ old('body', $article->body) }}</textarea>
                    @error('body')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $article->sort_order) }}" min="0" max="65535">
                    @error('sort_order')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field field--checkbox">
                    <label>
                        <input type="hidden" name="is_published" value="0">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $article->is_published) ? 'checked' : '' }}>
                        Published (visible on the public Help centre)
                    </label>
                    @error('is_published')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Save article</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Danger zone</h2></div>
        <div class="admin-panel__body">
            <p class="muted">Deleting this article permanently removes it from the Knowledge Base.</p>
            <form method="POST" action="{{ route('admin.help.articles.destroy', [$category, $article]) }}"
                  onsubmit="return confirm('Delete “{{ $article->title }}”? This cannot be undone.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete article</button>
            </form>
        </div>
    </div>
@endsection
