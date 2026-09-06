{{--
    A single sponsor on a public store surface (storefront listing + event page).

    Expects $sponsor as an array/object with:
      - path    (string)  the sponsor logo image path on the public disk
      - name    (?string) sponsor / company name
      - website (?string) external URL the logo/name links to
      - bio     (?string) short blurb

    The logo always shows. Name, bio and link are store-page-only extras: when
    any of them are present the logo becomes a click-to-reveal <details> that
    shows the sponsor's name, bio and website link. When the sponsor has only a
    logo it renders as a plain image (linked to the website if one is set).
--}}
@php
    $sponsorPath = is_array($sponsor) ? ($sponsor['path'] ?? null) : ($sponsor->path ?? null);
    $sponsorName = is_array($sponsor) ? ($sponsor['name'] ?? null) : ($sponsor->name ?? null);
    $sponsorWebsite = is_array($sponsor) ? ($sponsor['website'] ?? null) : ($sponsor->website ?? null);
    $sponsorBio = is_array($sponsor) ? ($sponsor['bio'] ?? null) : ($sponsor->bio ?? null);

    $sponsorImgUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($sponsorPath);
    $sponsorAlt = filled($sponsorName) ? $sponsorName : 'Sponsor';
    // Only offer the reveal when there is something extra to reveal.
    $hasDetails = filled($sponsorName) || filled($sponsorBio) || filled($sponsorWebsite);
@endphp

@if (! $hasDetails)
    <img class="store-sponsors__img"
         src="{{ $sponsorImgUrl }}"
         alt="{{ $sponsorAlt }}" loading="lazy">
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
