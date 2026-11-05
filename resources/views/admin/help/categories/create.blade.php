@extends('layouts.dashboard')

@section('title', 'Super-Admin — Add Knowledge Base section')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Add section</h1>
            <p>Create a new top-level section for the public Help centre.</p>
        </div>
        <div class="admin-head__actions">
            <a class="btn btn-outline" href="{{ route('admin.help.index') }}">Back</a>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.help.store') }}">
                @csrf

                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title') }}" required maxlength="255">
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="slug">Slug</label>
                    <p class="hint">Used in the public URL: <span class="mono">/help/your-slug</span>. Letters, numbers and hyphens only. Cannot be changed later.</p>
                    <input type="text" id="slug" name="slug" value="{{ old('slug') }}" required maxlength="100" pattern="[A-Za-z0-9-]+">
                    @error('slug')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="summary">Summary</label>
                    <p class="hint">A short one-line description shown under the section title.</p>
                    <input type="text" id="summary" name="summary" value="{{ old('summary') }}" maxlength="500">
                    @error('summary')<p class="error">{{ $message }}</p>@enderror
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
                        Published (visible on the public Help centre once it has a published article)
                    </label>
                    @error('is_published')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Create section</button>
                </div>
            </form>
        </div>
    </div>
@endsection
