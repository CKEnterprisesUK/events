<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * The result of a Nominatim geocode lookup: the resolved coordinates for a
 * free-text address query, plus the human-readable display name Nominatim
 * echoed back for the match. (Requirement 4.2)
 *
 * This is an immutable value object. Coordinates are decimal degrees
 * (latitude in [-90, 90], longitude in [-180, 180]).
 */
final class GeocodeResult
{
    /**
     * @param  float  $latitude  the resolved latitude in decimal degrees.
     * @param  float  $longitude  the resolved longitude in decimal degrees.
     * @param  ?string  $displayName  the human-readable name of the matched
     *   place, as returned by Nominatim; null when unavailable.
     */
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?string $displayName = null,
    ) {}
}
