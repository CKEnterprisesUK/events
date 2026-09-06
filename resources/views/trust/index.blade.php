@extends('layouts.app')

@section('title', 'Trust & Legal Centre')

@php
    // Short blurbs + icons for the well-known documents, so the cards read as
    // more than a title. Custom documents fall back to a neutral icon and no
    // blurb. Keyed by the stable slug.
    use App\Models\LegalDocument;

    $meta = [
        LegalDocument::SLUG_TERMS => [
            'icon' => '📄',
            'blurb' => 'The agreement that governs your use of the platform and our services.',
        ],
        LegalDocument::SLUG_PRIVACY => [
            'icon' => '🔒',
            'blurb' => 'How we collect, use, and protect your personal data.',
        ],
        LegalDocument::SLUG_PCI => [
            'icon' => '💳',
            'blurb' => 'Our commitment to secure payment card handling and PCI DSS compliance.',
        ],
        LegalDocument::SLUG_COOKIES => [
            'icon' => '🍪',
            'blurb' => 'The cookies we use and how you can control them.',
        ],
        LegalDocument::SLUG_ACCEPTABLE_USE => [
            'icon' => '✅',
            'blurb' => 'The rules for acceptable use of the platform.',
        ],
    ];
@endphp

@section('content')
    <section class="legal-centre">
        <header class="legal-centre__head">
            <h1>Trust &amp; Legal Centre</h1>
            <p class="legal-centre__intro">
                The policies and standards that govern how Events by CK Enterprises UK
                and the organisers on our platform operate. Choose a document to read it
                in full.
            </p>
        </header>

        @if ($documents->isEmpty())
            <p class="legal-centre__empty">Our policies are being prepared and will appear here shortly.</p>
        @else
            <div class="legal-centre__grid">
                @foreach ($documents as $document)
                    <a class="legal-card" href="{{ route('trust.show', $document->slug) }}">
                        <span class="legal-card__icon" aria-hidden="true">{{ $meta[$document->slug]['icon'] ?? '📘' }}</span>
                        <span class="legal-card__body">
                            <span class="legal-card__title">{{ $document->title }}</span>
                            @if (! empty($meta[$document->slug]['blurb']))
                                <span class="legal-card__blurb">{{ $meta[$document->slug]['blurb'] }}</span>
                            @endif
                        </span>
                        <span class="legal-card__go" aria-hidden="true">Read &rarr;</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endsection
