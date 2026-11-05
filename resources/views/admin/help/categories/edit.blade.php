@extends('layouts.dashboard')

@section('title', 'Super-Admin — Edit section ' . $category->title)

@section('content')
    <div class="admin-head">
        <div>
            <h1>Edit section: {{ $category->title }}</h1>
            <p>Public URL: <span class="mono">/help/{{ $category->slug }}</span></p>
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
            <form method="POST" action="{{ route('admin.help.update', $category) }}">
                @csrf
                @method('PUT')

                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title', $category->title) }}" required maxlength="255">
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="summary">Summary</label>
                    <p class="hint">A short one-line description shown under the section title.</p>
                    <input type="text" id="summary" name="summary" value="{{ old('summary', $category->summary) }}" maxlength="500">
                    @error('summary')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="sort_order">Sort order</label>
                    <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $category->sort_order) }}" min="0" max="65535">
                    @error('sort_order')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field field--checkbox">
                    <label>
                        <input type="hidden" name="is_published" value="0">
                        <input type="checkbox" name="is_published" value="1" {{ old('is_published', $category->is_published) ? 'checked' : '' }}>
                        Published (visible on the public Help centre once it has a published article)
                    </label>
                    @error('is_published')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Save section</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Danger zone</h2></div>
        <div class="admin-panel__body">
            <p class="muted">Deleting this section permanently removes it and all {{ $category->articles->count() }} of its {{ Str::plural('article', $category->articles->count()) }}.</p>
            <form method="POST" action="{{ route('admin.help.destroy', $category) }}"
                  onsubmit="return confirm('Delete “{{ $category->title }}” and all its articles? This cannot be undone.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete section</button>
            </form>
        </div>
    </div>
@endsection
