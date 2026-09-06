@extends('layouts.app')

@section('title', $document->title . ' — Trust & Legal Centre')

@section('content')
    <section class="legal-doc">
        <nav class="legal-doc__breadcrumb" aria-label="Breadcrumb">
            <a href="{{ route('trust.index') }}">Trust &amp; Legal Centre</a>
            <span aria-hidden="true">/</span>
            <span>{{ $document->title }}</span>
        </nav>

        <article class="legal-doc__body">
            <h1>{{ $document->title }}</h1>
            <p class="legal-doc__meta muted">Last updated {{ $document->updated_at?->format('j F Y') }}</p>

            <div class="prose">
                {!! Str::markdown($document->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
            </div>
        </article>

        @if ($documents->count() > 1)
            <aside class="legal-doc__more">
                <h2>Other policies</h2>
                <ul>
                    @foreach ($documents as $other)
                        @if ($other->slug !== $document->slug)
                            <li><a href="{{ route('trust.show', $other->slug) }}">{{ $other->title }}</a></li>
                        @endif
                    @endforeach
                </ul>
            </aside>
        @endif
    </section>
@endsection
