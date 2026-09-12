{{--
    Shared public site footer for the marketing site. Navy bar with the
    "Events by CK Enterprises" wordmark (unchanged) and grouped navigation:
    Product, Company, Trust & Legal and Account. Used by layouts.app so every
    public page shares one footer. Auth state comes from @auth/@else so the
    Account column mirrors the header (Dashboard when signed in). Styling:
    `.pub-footer*` in app.css.
--}}
<footer class="pub-footer">
    <div class="pub-footer__inner">
        <div class="pub-footer__top">
            <a class="pub-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                <span class="events">Events</span>
                <span class="by">by</span>
                <span class="ck">CK Enterprises</span>
            </a>

            <nav class="pub-footer__cols" aria-label="Footer">
                <div class="pub-footer__col">
                    <h2 class="pub-footer__heading">Product</h2>
                    <a href="{{ route('features') }}">Features</a>
                    <a href="{{ route('pricing') }}">Pricing</a>
                    <a href="{{ route('how-it-works') }}">How it works</a>
                    <a href="{{ route('for-charities') }}">For charities</a>
                </div>

                <div class="pub-footer__col">
                    <h2 class="pub-footer__heading">Company</h2>
                    <a href="https://ckenterprises.co.uk" target="_blank" rel="noopener">CK Enterprises UK</a>
                    <a href="https://ckenterprises.co.uk/#contact" target="_blank" rel="noopener">Contact</a>
                </div>

                <div class="pub-footer__col">
                    <h2 class="pub-footer__heading">Trust &amp; Legal</h2>
                    <a href="{{ route('trust.index') }}">Trust Centre</a>
                    <a href="{{ route('privacy') }}">Privacy</a>
                    <a href="{{ route('trust.show', \App\Models\LegalDocument::SLUG_TERMS) }}">Terms</a>
                </div>

                <div class="pub-footer__col">
                    <h2 class="pub-footer__heading">Account</h2>
                    @auth
                        <a href="{{ route('dashboard.home') }}">Dashboard</a>
                    @else
                        <a href="{{ url('/login') }}">Sign in</a>
                        <a href="{{ url('/register') }}">Get started</a>
                    @endauth
                </div>
            </nav>
        </div>
        <div class="pub-footer__copy">
            &copy; {{ date('Y') }} <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises UK</a> Product. All rights reserved.
        </div>
    </div>
</footer>
