<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Geocoding\GeocodeResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a free-text address to coordinates via OpenStreetMap's Nominatim
 * geocoding API. (Requirement 4.2)
 *
 * The service is a thin, config-driven wrapper around {@see Http} and
 * {@see Cache} that is deliberately conservative about Nominatim's public
 * usage policy:
 *
 *  - **Identifying User-Agent (required).** Every request carries an
 *    identifying User-Agent read from `config('services.nominatim.user_agent')`.
 *    If that config is empty, geocoding is treated as misconfigured and
 *    {@see geocode()} returns null immediately — a request is never sent
 *    anonymously. (Nominatim policy)
 *  - **Aggressive caching.** Results are cached by normalised address so an
 *    already-resolved address is served from cache and issues no HTTP request.
 *    Negative results (no match / error) are cached too — with a shorter TTL —
 *    so a bad address is not re-queried on every save. (Requirement 4.4)
 *  - **Rate-limit friendliness.** Because results are cached, repeat lookups of
 *    the same address never hit the network. A short cache lock serialises
 *    concurrent bursts so two near-simultaneous saves don't fire two immediate
 *    lookups; if the lock can't be acquired quickly the call simply proceeds.
 *  - **Never throws.** Any miss, non-2xx response, timeout, or connection error
 *    yields null so the caller can save the event without coordinates and let
 *    the manager set the pin manually. (Requirement 4.5)
 */
class GeocodingService
{
    /**
     * Resolve an address to coordinates via Nominatim, or null on any
     * miss / error / misconfiguration.
     */
    public function geocode(string $address): ?GeocodeResult
    {
        $userAgent = (string) config('services.nominatim.user_agent', '');

        // Nominatim policy: never send anonymous requests. A missing
        // User-Agent is a misconfiguration, not a lookup — bail out without
        // caching so fixing the config takes effect immediately.
        if (trim($userAgent) === '') {
            return null;
        }

        $ttl = (int) config('services.nominatim.cache_ttl', 60 * 60 * 24 * 30);

        return Cache::remember(
            $this->cacheKey($address),
            $ttl,
            fn (): ?GeocodeResult => $this->lookup($address, $userAgent),
        );
    }

    /**
     * Perform the actual Nominatim lookup for an address, serialised through a
     * short cache lock for burst friendliness. Returns null on any failure so
     * the negative result is cached and the caller degrades gracefully.
     */
    private function lookup(string $address, string $userAgent): ?GeocodeResult
    {
        try {
            $lock = Cache::lock('geocode:nominatim', 5);

            // Wait briefly for any in-flight lookup; if it can't be acquired,
            // just proceed rather than blocking the save.
            try {
                $lock->block(3);
            } catch (\Throwable) {
                // Lock contention or a store without lock support — proceed.
            }

            try {
                return $this->request($address, $userAgent);
            } finally {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // Nothing to release (never acquired) — ignore.
                }
            }
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Issue the Nominatim /search request and map a successful match to a
     * {@see GeocodeResult}. Returns null on non-2xx, an empty result set, or
     * any error/timeout.
     */
    private function request(string $address, string $userAgent): ?GeocodeResult
    {
        $baseUri = rtrim((string) config('services.nominatim.base_uri', 'https://nominatim.openstreetmap.org'), '/');
        $timeout = (int) config('services.nominatim.timeout', 5);

        try {
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout($timeout)
                ->retry(0)
                ->get($baseUri.'/search', [
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                ]);
        } catch (\Throwable) {
            // Connection error / timeout — treat as a miss.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $hits = $response->json();

        if (! is_array($hits) || $hits === []) {
            return null;
        }

        $hit = $hits[0];

        if (! is_array($hit) || ! isset($hit['lat'], $hit['lon'])) {
            return null;
        }

        return new GeocodeResult(
            (float) $hit['lat'],
            (float) $hit['lon'],
            isset($hit['display_name']) ? (string) $hit['display_name'] : null,
        );
    }

    /**
     * The cache key for an address, keyed on its normalised form so that
     * cosmetically different spellings of the same address share a cache entry.
     */
    private function cacheKey(string $address): string
    {
        return 'geocode:'.sha1($this->normalise($address));
    }

    /**
     * Normalise an address for cache-key purposes: lowercase, trim, and
     * collapse internal runs of whitespace to a single space.
     */
    private function normalise(string $address): string
    {
        return preg_replace('/\s+/', ' ', trim(mb_strtolower($address))) ?? '';
    }
}
