{{--
    Shared SEO head tags for the marketing pages. Each page @includes this with
    a $metaTitle, $description and (optional) $canonical, pushed into
    layouts.app's `head` stack. Keeps meta consistent without a heavier SEO
    dependency.

    @param string $metaTitle    The page title (also set via @section('title')).
    @param string $description  Unique, non-stuffed meta description.
    @param string|null $canonical  Absolute canonical URL (defaults to current).
--}}
@push('head')
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta property="og:site_name" content="Events by CK Enterprises UK">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:type" content="website">
@endpush
