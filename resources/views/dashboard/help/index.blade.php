@extends('layouts.dashboard')

@section('title', 'Help & knowledge')

@push('head')
<style>
    .help-intro { max-width: 760px; margin-bottom: 1.5rem; }
    .help-toc {
        display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 1rem 0 2rem;
    }
    .help-toc a {
        display: inline-block; padding: 0.4rem 0.8rem; border: 1px solid var(--border);
        border-radius: 999px; font-size: 0.9rem; text-decoration: none; color: inherit;
    }
    .help-toc a:hover { border-color: var(--brand); }
    .help-section { margin-bottom: 2rem; scroll-margin-top: 1rem; }
    .help-section > .panel__head p { margin: 0.25rem 0 0; }
    .help-article { padding: 1.1rem 1.25rem; border-top: 1px solid var(--border); }
    .help-article:first-of-type { border-top: 0; }
    .help-article h3 { margin: 0 0 0.5rem; font-size: 1.05rem; }
    .help-article p { margin: 0 0 0.6rem; max-width: 760px; line-height: 1.55; }
    .help-article p:last-child { margin-bottom: 0; }
    .help-article ul { margin: 0.4rem 0 0.6rem 1.1rem; max-width: 760px; line-height: 1.5; }
    .help-article ul li { margin-bottom: 0.3rem; }
    .help-cta {
        display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;
        justify-content: space-between;
    }
    .help-cta p { margin: 0; max-width: 560px; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Help &amp; knowledge</h1>
        <div>
            <a class="btn" href="{{ route('dashboard.support.create') }}">Contact support</a>
        </div>
    </div>

    <p class="help-intro muted">
        Answers to the questions we hear most. Browse a topic below, and if you
        still need a hand, raise a support request and the CK Enterprises team
        will help.
    </p>

    <nav class="help-toc" aria-label="Help topics">
        @foreach ($sections as $section)
            <a href="#{{ $section['key'] }}">{{ $section['title'] }}</a>
        @endforeach
    </nav>

    @foreach ($sections as $section)
        <section class="panel help-section" id="{{ $section['key'] }}">
            <div class="panel__head">
                <h2>{{ $section['title'] }}</h2>
                <p class="muted">{{ $section['summary'] }}</p>
            </div>

            @foreach ($section['articles'] as $article)
                <article class="help-article">
                    <h3>{{ $article['title'] }}</h3>
                    @foreach ($article['body'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                    @if (! empty($article['list']))
                        <ul>
                            @foreach ($article['list'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach
        </section>
    @endforeach

    <section class="panel help-section">
        <div class="help-article help-cta">
            <p>
                <strong>Didn’t find what you needed?</strong>
                Send us a support request and we’ll get back to you by email.
            </p>
            <a class="btn" href="{{ route('dashboard.support.create') }}">Contact support</a>
        </div>
    </section>
@endsection
