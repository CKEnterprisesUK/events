@extends('layouts.dashboard')

@section('title', 'Super-Admin — Knowledge Base')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Knowledge Base</h1>
            <p>Author and publish the public Help centre of Events by CK Enterprises UK. Published articles appear at <a href="{{ route('help.index') }}" target="_blank" rel="noopener">/help</a>, in the site footer and in the in-app Help portal.</p>
        </div>
        <div class="admin-head__actions">
            <a class="btn" href="{{ route('admin.help.create') }}">Add section</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Sections</h2></div>

        @if ($categories->isEmpty())
            <div class="admin-panel__body">
                <p class="muted">No sections yet. Add your first section to start building the Knowledge Base.</p>
            </div>
        @else
            <table class="admin-facts">
                <thead>
                    <tr>
                        <th scope="col">Section</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Articles</th>
                        <th scope="col">Status</th>
                        <th scope="col">Order</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($categories as $category)
                        <tr>
                            <td>{{ $category->title }}</td>
                            <td class="mono">{{ $category->slug }}</td>
                            <td class="mono">{{ $category->articles_count }}</td>
                            <td>
                                @if ($category->is_published)
                                    <span class="admin-pill admin-pill--active">Published</span>
                                @else
                                    <span class="admin-pill">Draft</span>
                                @endif
                            </td>
                            <td class="mono">{{ $category->sort_order }}</td>
                            <td><a href="{{ route('admin.help.show', $category) }}">Manage</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
