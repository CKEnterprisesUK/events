@extends('layouts.app')

@section('title', 'Trust Centre · Events by CK Enterprises UK')

@include('marketing._meta', [
    'metaTitle' => 'Trust Centre',
    'description' => 'Security, privacy, payments, accessibility and legal information for Events by CK Enterprises UK.',
    'canonical' => route('trust.index'),
])

@php
    use App\Models\LegalDocument;

    // Short blurbs + icons for the well-known documents, so the policy cards
    // read as more than a title. Custom documents fall back to a neutral icon
    // and no blurb. Keyed by the stable slug.
    $meta = [
        LegalDocument::SLUG_TERMS => ['icon' => '📄', 'blurb' => 'The agreement that governs your use of the platform and our services.'],
        LegalDocument::SLUG_PRIVACY => ['icon' => '🔒', 'blurb' => 'How we collect, use, and protect your personal data.'],
        LegalDocument::SLUG_PCI => ['icon' => '💳', 'blurb' => 'Our commitment to secure payment card handling and PCI DSS compliance.'],
        LegalDocument::SLUG_COOKIES => ['icon' => '🍪', 'blurb' => 'The cookies we use and how you can control them.'],
        LegalDocument::SLUG_ACCEPTABLE_USE => ['icon' => '✅', 'blurb' => 'The rules for acceptable use of the platform.'],
    ];
@endphp

@section('content')
    <div class="mkt">
        <section class="mkt-section" aria-labelledby="trust-title" style="padding-top: 2.4rem;">
            <div class="mkt-container">
                <div class="mkt-section-head">
                    <span class="mkt-eyebrow">Trust Centre</span>
                    <h1 id="trust-title">Security, privacy, payments and policies.</h1>
                    <p>
                        The information and policies that govern how Events by CK Enterprises UK — and the
                        organisers on our platform — operate. Use this page as your starting point.
                    </p>
                </div>

                <div class="mkt-grid mkt-grid--2">
                    <div class="mkt-cell">
                        <span class="mkt-cell__ico" aria-hidden="true">🛡️</span>
                        <h2 style="font-size: 1.08rem;">Security</h2>
                        <p>Organiser accounts support two-factor authentication, and key actions are recorded in an activity trail. Payments are handled by Stripe rather than stored on the platform.</p>
                    </div>
                    <div class="mkt-cell">
                        <span class="mkt-cell__ico" aria-hidden="true">💳</span>
                        <h2 style="font-size: 1.08rem;">Payments</h2>
                        <p>
                            Card payments and payouts are processed through Stripe on your own connected account.
                            @if ($documents->firstWhere('slug', LegalDocument::SLUG_PCI))
                                Read our <a href="{{ route('trust.show', LegalDocument::SLUG_PCI) }}">PCI DSS statement</a> for details.
                            @endif
                        </p>
                    </div>
                    <div class="mkt-cell">
                        <span class="mkt-cell__ico" aria-hidden="true">🔒</span>
                        <h2 style="font-size: 1.08rem;">Privacy &amp; data protection</h2>
                        <p>
                            We keep customer data focused on the event, with no advertising trackers. See our
                            <a href="{{ route('privacy') }}">privacy policy</a> for how data is handled and retained.
                        </p>
                    </div>
                    <div class="mkt-cell">
                        <span class="mkt-cell__ico" aria-hidden="true">♿</span>
                        <h2 style="font-size: 1.08rem;">Accessibility</h2>
                        <p>We aim for an accessible purchasing and organiser experience. If you hit a barrier, contact us and we'll help.</p>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <section class="legal-centre">
        <header class="legal-centre__head">
            <h2>Policies</h2>
            <p class="legal-centre__intro">
                The published policies and standards for Events by CK Enterprises UK. Choose a document to read it in full.
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
