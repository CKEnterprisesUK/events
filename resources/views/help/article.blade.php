@extends('layouts.app')

@section('title', $article->title . ' · Help centre')

@include('marketing._meta', [
    'metaTitle' => $article->title . ' · Help centre',
    'description' => Str::limit(trim(strip_tags(Str::markdown($article->body))), 155),
    'canonical' => route('help.article', [$category->slug, $article->slug]),
])

@section('content')
    <section class="kb">
        <nav class="kb__breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('help.index') }}">Help centre</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('help.category', $category->slug) }}">{{ $category->title }}</a>
            <span aria-hidden="true">/</span>
            <span>{{ $article->title }}</span>
        </nav>

        <div class="kb__layout">
            <article class="kb__article">
                <h1>{{ $article->title }}</h1>
                <p class="kb__meta muted">Last updated {{ $article->updated_at?->format('j F Y') }}</p>

                <div class="prose">
                    {!! Str::markdown($article->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                </div>

                <p class="kb__still-stuck muted">
                    Still stuck? If you have an organiser account,
                    <a href="{{ route('login') }}">sign in</a> and contact our support team and we'll help by email.
                </p>
            </article>

            @if ($related->count() > 1)
                <aside class="kb__aside" aria-label="More in {{ $category->title }}">
                    <h2>More in {{ $category->title }}</h2>
                    <ul>
                        @foreach ($related as $other)
                            <li>
                                <a href="{{ route('help.article', [$category->slug, $other->slug]) }}"
                                   @if ($other->slug === $article->slug) aria-current="page" class="is-current" @endif>
                                    {{ $other->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </aside>
            @endif
        </div>

        @include('help._other-topics', ['categories' => $categories, 'current' => $category])
    </section>
@endsection
