@extends('layouts.app')

@section('title', 'Help centre · Events by CK Enterprises UK')

@include('marketing._meta', [
    'metaTitle' => 'Help centre',
    'description' => 'Guides and answers for organisers using Events by CK Enterprises UK — getting started, events, payments, orders and more.',
    'canonical' => route('help.index'),
])

@section('content')
    <div class="mkt">
        <section class="mkt-section" aria-labelledby="help-title" style="padding-top: 2.4rem;">
            <div class="mkt-container">
                <div class="mkt-section-head">
                    <span class="mkt-eyebrow">Help centre</span>
                    <h1 id="help-title">How can we help?</h1>
                    <p>
                        Guides and answers for organisers using Events by CK Enterprises UK.
                        Browse a topic below. If you still need a hand and you have an account,
                        you can <a href="{{ route('login') }}">sign in</a> and contact our support team.
                    </p>
                </div>
            </div>
        </section>
    </div>

    <section class="legal-centre">
        <header class="legal-centre__head">
            <h2>Browse topics</h2>
            <p class="legal-centre__intro">
                Choose a topic to see its articles.
            </p>
        </header>

        @if ($categories->isEmpty())
            <p class="legal-centre__empty">Our help articles are being prepared and will appear here shortly.</p>
        @else
            <div class="legal-centre__grid">
                @foreach ($categories as $category)
                    <a class="legal-card" href="{{ route('help.category', $category->slug) }}">
                        <span class="legal-card__body">
                            <span class="legal-card__title">{{ $category->title }}</span>
                            @if (filled($category->summary))
                                <span class="legal-card__blurb">{{ $category->summary }}</span>
                            @endif
                            <span class="legal-card__blurb">{{ $category->publishedArticles->count() }} {{ Str::plural('article', $category->publishedArticles->count()) }}</span>
                        </span>
                        <span class="legal-card__go" aria-hidden="true">Read &rarr;</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
