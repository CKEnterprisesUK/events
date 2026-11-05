@extends('layouts.dashboard')

@section('title', 'Super-Admin — Add article')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Add article</h1>
            <p>New article in <strong>{{ $category->title }}</strong>.</p>
        </div>
        <div class="admin-head__actions">
            <a class="btn btn-outline" href="{{ route('admin.help.show', $category) }}">Back</a>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.help.articles.store', $category) }}">
                @csrf

                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title') }}" required maxlength="255">
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="slug">Slug</label>
                    <p class="hint">Used in the public URL: <span class="mono">/help/{{ $category->slug }}/your-slug</span>. Letters, numbers and hyphens only. Cannot be changed later.</p>
                    <input type="text" id="slug" name="slug" value="{{ old('slug') }}" required maxlength="150" pattern="[A-Za-z0-9-]+">
                    @error('slug')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="body">Content</label>
                    <p class="hint">Markdown is supported (headings, lists, links, bold/italic). Raw HTML is escaped.</p>
                    <textarea id="body" name="body" rows="18">{{ old('body') }}</textarea>
                    @error('body')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="65535">
                    @error('sort_order')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field field--checkbox">
                    <label>
                        <input type="hidden" name="is_published" value="0">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', true) ? 'checked' : '' }}>
                        Published (visible on the public Help centre)
                    </label>
                    @error('is_published')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Create article</button>
                </div>
            </form>
        </div>
    </div>
@endsection
