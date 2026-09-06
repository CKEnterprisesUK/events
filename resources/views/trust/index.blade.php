@extends('layouts.app')

@section('title', 'Trust & Legal Centre')

@section('content')
    <section class="legal-centre">
        <header class="legal-centre__head">
            <h1>Trust &amp; Legal Centre</h1>
            <p class="muted">
                The policies and standards that govern how Events by CK Enterprises UK
                and the organisers on our platform operate. Choose a document to read it
                in full.
            </p>
        </header>

        @if ($documents->isEmpty())
            <p class="muted">Our policies are being prepared and will appear here shortly.</p>
        @else
            <ul class="legal-centre__list">
                @foreach ($documents as $document)
                    <li class="legal-centre__item">
                        <a href="{{ route('trust.show', $document->slug) }}">
                            <span class="legal-centre__item-title">{{ $document->title }}</span>
                            <span class="legal-centre__item-go" aria-hidden="true">&rarr;</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
