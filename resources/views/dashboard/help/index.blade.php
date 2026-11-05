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
    .help-article .prose { max-width: 760px; }
    .help-article .prose > :first-child { margin-top: 0; }
    .help-article .prose p:last-child { margin-bottom: 0; }
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
            <a class="btn btn-outline" href="{{ route('help.index') }}" target="_blank" rel="noopener">Open public Help centre</a>
            <a class="btn" href="{{ route('dashboard.support.create') }}">Contact support</a>
        </div>
    </div>

    <p class="help-intro muted">
        Answers to the questions we hear most. Browse a topic below, and if you
        still need a hand, raise a support request and the CK Enterprises team
        will help.
    </p>

    @if ($categories->isEmpty())
        <section class="panel help-section">
            <div class="help-article">
                <p class="muted">Our help articles are being prepared and will appear here shortly.</p>
            </div>
        </section>
    @else
        <nav class="help-toc" aria-label="Help topics">
            @foreach ($categories as $category)
                <a href="#{{ $category->slug }}">{{ $category->title }}</a>
            @endforeach
        </nav>

        @foreach ($categories as $category)
            <section class="panel help-section" id="{{ $category->slug }}">
                <div class="panel__head">
                    <h2>{{ $category->title }}</h2>
                    @if (filled($category->summary))
                        <p class="muted">{{ $category->summary }}</p>
                    @endif
                </div>

                @foreach ($category->publishedArticles as $article)
                    <article class="help-article">
                        <h3>{{ $article->title }}</h3>
                        <div class="prose">
                            {!! Str::markdown($article->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                        </div>
                    </article>
                @endforeach
            </section>
        @endforeach
    @endif

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
