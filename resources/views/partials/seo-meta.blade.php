{{--
    Reusable SEO meta block for public pages (storefront + event pages).

    Emits: meta description, canonical link, Open Graph tags and Twitter Card
    tags so search engines get a clean snippet and shared links render a rich
    preview. Designed to be @included inside an @push('head') on any public
    page. Non-indexable/private pages (dashboard, embeds, checkout) must NOT
    include this.

    Expected variables (pass via @include('partials.seo-meta', [...])):
      - $seoTitle       string  Full page title (also used as og:title).
      - $seoDescription ?string Plain-text description (~<=160 chars ideal).
      - $seoCanonical   string  Absolute canonical URL for this page.
      - $seoImage       ?string Absolute image URL for og:image / twitter image.
      - $seoType        ?string Open Graph type: 'website' (default) or 'article'.
      - $seoSiteName    ?string og:site_name (defaults to config app name).

    All values are escaped. Description is normalised to a single trimmed,
    collapsed-whitespace line and hard-capped so we never emit an unbounded blob
    from free-text fields (about_text / event description).
--}}
@php
    $seoType = $seoType ?? 'website';
    $seoSiteName = $seoSiteName ?? config('app.name', 'Event Ticketing Platform');

    // Normalise free-text into a clean single-line snippet: strip tags, collapse
    // whitespace, trim, then cap at ~160 chars on a word boundary with an
    // ellipsis. Null/blank descriptions simply omit the tags.
    $seoDescriptionClean = null;
    if (! empty($seoDescription)) {
        $normalised = trim(preg_replace('/\s+/', ' ', strip_tags((string) $seoDescription)));
        if ($normalised !== '') {
            if (mb_strlen($normalised) > 160) {
                $truncated = mb_substr($normalised, 0, 157);
                $lastSpace = mb_strrpos($truncated, ' ');
                if ($lastSpace !== false && $lastSpace > 100) {
                    $truncated = mb_substr($truncated, 0, $lastSpace);
                }
                $normalised = rtrim($truncated).'…';
            }
            $seoDescriptionClean = $normalised;
        }
    }
@endphp
    @if ($seoDescriptionClean !== null)
    <meta name="description" content="{{ $seoDescriptionClean }}">
    @endif
    <link rel="canonical" href="{{ $seoCanonical }}">

    {{-- Open Graph (Facebook, LinkedIn, WhatsApp, Slack, etc.) --}}
    <meta property="og:type" content="{{ $seoType }}">
    <meta property="og:site_name" content="{{ $seoSiteName }}">
    <meta property="og:title" content="{{ $seoTitle }}">
    @if ($seoDescriptionClean !== null)
    <meta property="og:description" content="{{ $seoDescriptionClean }}">
    @endif
    <meta property="og:url" content="{{ $seoCanonical }}">
    @if (! empty($seoImage))
    <meta property="og:image" content="{{ $seoImage }}">
    @endif

    {{-- Twitter / X Card --}}
    <meta name="twitter:card" content="{{ ! empty($seoImage) ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $seoTitle }}">
    @if ($seoDescriptionClean !== null)
    <meta name="twitter:description" content="{{ $seoDescriptionClean }}">
    @endif
    @if (! empty($seoImage))
    <meta name="twitter:image" content="{{ $seoImage }}">
    @endif
