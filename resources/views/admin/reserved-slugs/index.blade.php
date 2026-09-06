@extends('layouts.dashboard')

@section('title', 'Super-Admin — Reserved slugs')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Reserved slugs</h1>
            <p>Slugs a Company can never claim at sign-up or when changing its storefront address. This blocks reserved platform routes (like <span class="mono">admin</span> or <span class="mono">login</span>), infrastructure paths, and any brand or abuse words you want to keep off the platform.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Add a reserved slug</h2></div>
        <div class="admin-panel__body">
            <form method="POST" action="{{ route('admin.reserved-slugs.store') }}">
                @csrf

                <div class="field">
                    <label for="slug">Slug</label>
                    <p class="hint">Lowercase letters, numbers and hyphens only — the same shape a storefront address takes.</p>
                    <input type="text" id="slug" name="slug" value="{{ old('slug') }}" required maxlength="255" pattern="[a-z0-9-]+">
                    @error('slug')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="reason">Reason <span class="hint">(optional)</span></label>
                    <input type="text" id="reason" name="reason" value="{{ old('reason') }}" maxlength="255">
                    @error('reason')<p class="error">{{ $message }}</p>@enderror
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn">Reserve slug</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Reserved slugs</h2></div>
        <table class="admin-facts">
            <thead>
                <tr>
                    <th scope="col">Slug</th>
                    <th scope="col">Reason</th>
                    <th scope="col">Type</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($slugs as $slug)
                    <tr>
                        <td class="mono">{{ $slug->slug }}</td>
                        <td>{{ $slug->reason ?? '—' }}</td>
                        <td>
                            @if ($slug->is_system)
                                <span class="admin-pill">System</span>
                            @else
                                <span class="admin-pill admin-pill--active">Custom</span>
                            @endif
                        </td>
                        <td>
                            @if ($slug->is_system)
                                <span class="hint">Protected</span>
                            @else
                                <form method="POST" action="{{ route('admin.reserved-slugs.destroy', $slug) }}" onsubmit="return confirm('Remove &quot;{{ $slug->slug }}&quot; from the blocklist? A Company could then claim it.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
