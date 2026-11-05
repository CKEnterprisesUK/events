@extends('layouts.app')

@section('title', $category->title . ' · Help centre')

@include('marketing._meta', [
    'metaTitle' => $category->title . ' · Help centre',
    'description' => $category->summary ?: ('Help articles about ' . $category->title . ' for Events by CK Enterprises UK.'),
    'canonical' => route('help.category', $category->slug),
])

@section('content')
    <section class="kb">
        <nav class="kb__breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('help.index') }}">Help centre</a>
            <span aria-hidden="true">/</span>
            <span>{{ $category->title }}</span>
        </nav>

        <header class="kb__head">
            <h1>{{ $category->title }}</h1>
            @if (filled($category->summary))
                <p class="kb__lede">{{ $category->summary }}</p>
            @endif
        </header>

        <ul class="kb__list">
            @foreach ($articles as $article)
                <li>
                    <a class="kb__list-item" href="{{ route('help.article', [$category->slug, $article->slug]) }}">
                        <span class="kb__list-title">{{ $article->title }}</span>
                        <span class="kb__list-go" aria-hidden="true">&rarr;</span>
                    </a>
                </li>
            @endforeach
        </ul>

        @include('help._other-topics', ['categories' => $categories, 'current' => $category])
    </section>
@endsection
