<footer class="site-footer">
    <div class="site-footer__inner">
        <div class="site-footer__brand">
            <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name', 'Event Ticketing Platform') }}" class="site-footer__logo">
            <p class="site-footer__tagline">Branded ticketing and direct payouts for event organisers.</p>
        </div>

        <nav class="site-footer__nav" aria-label="Footer">
            <div class="site-footer__col">
                <h4>Platform</h4>
                <a href="{{ url('/') }}">Home</a>
                <a href="{{ url('/login') }}">Log in</a>
                <a href="{{ url('/register') }}">Sign up</a>
            </div>
            <div class="site-footer__col">
                <h4>Legal</h4>
                <a href="{{ route('trust.index') }}">Trust &amp; Legal Centre</a>
                <a href="{{ route('trust.show', \App\Models\LegalDocument::SLUG_TERMS) }}">Terms &amp; conditions</a>
                <a href="{{ route('trust.show', \App\Models\LegalDocument::SLUG_PRIVACY) }}">Privacy notice</a>
            </div>
            <div class="site-footer__col">
                <h4>Contact</h4>
                <a href="mailto:support@ckenterprises.co.uk">support@ckenterprises.co.uk</a>
                <a href="https://ckenterprises.co.uk" target="_blank" rel="noopener">ckenterprises.co.uk</a>
            </div>
        </nav>
    </div>

    <div class="site-footer__bar">
        <span>&copy; {{ date('Y') }} CK Enterprises. All rights reserved.</span>
    </div>
</footer>
