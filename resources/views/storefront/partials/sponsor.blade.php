{{--
    A single sponsor on a public store surface (storefront listing + event page).

    Expects $sponsor as an array/object with:
      - path    (string)  the sponsor logo image path on the public disk
      - name    (?string) sponsor / company name
      - website (?string) external URL the logo/name links to
      - bio     (?string) short blurb

    The logo always shows. Name, bio and link are store-page-only extras.

    By default (storefront listing) the logo becomes a click-to-reveal
    <details> that shows the sponsor's name, bio and website link. Pass
    `expanded => true` (as the event page does) to render those details
    inline and always visible instead of behind a click. When the sponsor has
    only a logo it renders as a plain image (linked to the website if set).
--}}
@php
    $sponsorPath = is_array($sponsor) ? ($sponsor['path'] ?? null) : ($sponsor->path ?? null);
    $sponsorName = is_array($sponsor) ? ($sponsor['name'] ?? null) : ($sponsor->name ?? null);
    $sponsorWebsite = is_array($sponsor) ? ($sponsor['website'] ?? null) : ($sponsor->website ?? null);
    $sponsorBio = is_array($sponsor) ? ($sponsor['bio'] ?? null) : ($sponsor->bio ?? null);

    $expanded = $expanded ?? false;
    $sponsorImgUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($sponsorPath);
    $sponsorAlt = filled($sponsorName) ? $sponsorName : 'Sponsor';
    // Only offer the reveal when there is something extra to reveal.
    $hasDetails = filled($sponsorName) || filled($sponsorBio) || filled($sponsorWebsite);
@endphp

@if (! $hasDetails)
    @if (filled($sponsorWebsite))
        <a class="store-sponsor__logolink" href="{{ $sponsorWebsite }}" target="_blank" rel="noopener nofollow ugc">
            <img class="store-sponsors__img" src="{{ $sponsorImgUrl }}" alt="{{ $sponsorAlt }}" loading="lazy">
        </a>
    @else
        <img class="store-sponsors__img"
             src="{{ $sponsorImgUrl }}"
             alt="{{ $sponsorAlt }}" loading="lazy">
    @endif
@elseif ($expanded)
    {{-- Event page: sponsor details shown inline, no click required. --}}
    <div class="store-sponsor store-sponsor--expanded">
        <div class="store-sponsor__logo">
            <img class="store-sponsors__img"
                 src="{{ $sponsorImgUrl }}"
                 alt="{{ $sponsorAlt }}" loading="lazy">
        </div>
        <div class="store-sponsor__info">
            @if (filled($sponsorName))
                <p class="store-sponsor__name">{{ $sponsorName }}</p>
            @endif
            @if (filled($sponsorBio))
                <p class="store-sponsor__bio">{!! nl2br(e($sponsorBio)) !!}</p>
            @endif
            @if (filled($sponsorWebsite))
                <p class="store-sponsor__link">
                    <a href="{{ $sponsorWebsite }}" target="_blank" rel="noopener nofollow ugc">
                        Visit {{ filled($sponsorName) ? $sponsorName : 'website' }}
                    </a>
                </p>
            @endif
        </div>
    </div>
@else
    <details class="store-sponsor">
        <summary class="store-sponsor__summary" title="{{ filled($sponsorName) ? 'About '.$sponsorName : 'About this sponsor' }}">
            <img class="store-sponsors__img"
                 src="{{ $sponsorImgUrl }}"
                 alt="{{ $sponsorAlt }}" loading="lazy">
        </summary>
        <div class="store-sponsor__info">
            @if (filled($sponsorName))
                <p class="store-sponsor__name">{{ $sponsorName }}</p>
            @endif
            @if (filled($sponsorBio))
                <p class="store-sponsor__bio">{!! nl2br(e($sponsorBio)) !!}</p>
            @endif
            @if (filled($sponsorWebsite))
                <p class="store-sponsor__link">
                    <a href="{{ $sponsorWebsite }}" target="_blank" rel="noopener nofollow ugc">
                        Visit {{ filled($sponsorName) ? $sponsorName : 'website' }}
                    </a>
                </p>
            @endif
        </div>
    </details>
@endif
