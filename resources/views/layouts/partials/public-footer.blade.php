{{--
    Shared public site footer, matching the landing page chrome. Navy bar with
    the "Events by CK Enterprises" wordmark, primary + legal links, and the
    "CK Enterprises UK Product" copyright line. Used by layouts.app so public
    pages share the same footer as the main landing page. Styling: `.pub-footer*`
    in app.css.
--}}
<footer class="pub-footer">
    <div class="pub-footer__inner">
        <div class="pub-footer__top">
            <a class="pub-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                <span class="events">Events</span>
                <span class="by">by</span>
                <span class="ck">CK Enterprises</span>
            </a>
            <nav class="pub-footer__links" aria-label="Footer">
                <a href="{{ url('/') }}">Home</a>
                <a href="{{ url('/register') }}">Get started</a>
                <a href="{{ route('trust.index') }}">Trust &amp; Legal</a>
                <a href="{{ route('trust.show', \App\Models\LegalDocument::SLUG_TERMS) }}">Terms</a>
                <a href="{{ route('trust.show', \App\Models\LegalDocument::SLUG_PRIVACY) }}">Privacy</a>
                <a href="https://ckenterprises.co.uk" target="_blank" rel="noopener">ckenterprises.co.uk</a>
            </nav>
        </div>
        <div class="pub-footer__copy">
            &copy; {{ date('Y') }} <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises UK</a> Product. All rights reserved.
        </div>
    </div>
</footer>
