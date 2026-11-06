@extends('layouts.dashboard')

@section('title', 'Super-Admin — ' . $category->title)

@section('content')
    <div class="admin-head">
        <div>
            <h1>{{ $category->title }}</h1>
            <p>
                Section of the Help centre.
                Public URL: <span class="mono">/help/{{ $category->slug }}</span>
                @if ($category->is_published)
                    (<a href="{{ route('help.category', $category->slug) }}" target="_blank" rel="noopener">view</a>)
                @endif
            </p>
        </div>
        <div class="admin-head__actions">
            <a class="btn btn-outline" href="{{ route('admin.help.index') }}">All sections</a>
            <a class="btn btn-outline" href="{{ route('admin.help.edit', $category) }}">Edit section</a>
            <a class="btn" href="{{ route('admin.help.articles.create', $category) }}">Add article</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Articles</h2></div>

        @if ($articles->isEmpty())
            <div class="admin-panel__body">
                <p class="muted">No articles in this section yet. Add one to get started — the section only appears publicly once it has a published, non-empty article.</p>
            </div>
        @else
            <table class="admin-facts">
                <thead>
                    <tr>
                        <th scope="col">Title</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Status</th>
                        <th scope="col">Order</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($articles as $article)
                        <tr>
                            <td>{{ $article->title }}</td>
                            <td class="mono">{{ $article->slug }}</td>
                            <td>
                                @if ($article->isLive())
                                    <span class="admin-pill admin-pill--active">Published</span>
                                @elseif (! $article->hasBody())
                                    <span class="admin-pill admin-pill--suspended">Empty</span>
                                @else
                                    <span class="admin-pill">Draft</span>
                                @endif
                            </td>
                            <td class="mono">{{ $article->sort_order }}</td>
                            <td><a href="{{ route('admin.help.articles.edit', [$category, $article]) }}">Edit</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
