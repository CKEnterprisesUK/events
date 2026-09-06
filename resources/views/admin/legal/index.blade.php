@extends('layouts.dashboard')

@section('title', 'Super-Admin — Trust & Legal Centre')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Trust &amp; Legal Centre</h1>
            <p>Author and publish the Platform policies of Events by CK Enterprises UK. Published documents appear at <a href="{{ route('trust.index') }}" target="_blank" rel="noopener">/trust</a> and in the site footer.</p>
        </div>
        <div class="admin-head__actions">
            <a class="btn" href="{{ route('admin.legal.create') }}">Add document</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Documents</h2></div>
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
                @foreach ($documents as $document)
                    <tr>
                        <td>{{ $document->title }}</td>
                        <td class="mono">{{ $document->slug }}</td>
                        <td>
                            @if ($document->is_published && $document->hasBody())
                                <span class="admin-pill admin-pill--active">Published</span>
                            @elseif (! $document->hasBody())
                                <span class="admin-pill admin-pill--suspended">Empty</span>
                            @else
                                <span class="admin-pill">Draft</span>
                            @endif
                        </td>
                        <td class="mono">{{ $document->sort_order }}</td>
                        <td><a href="{{ route('admin.legal.edit', $document) }}">Edit</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
