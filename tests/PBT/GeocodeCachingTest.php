<?php

namespace Tests\PBT;

use App\Services\Geocoding\GeocodeResult;
use App\Services\GeocodingService;
use Eris\Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Property-based test for geocode caching and the identifying User-Agent
 * (Requirement 4.4).
 *
 * This is a pure service test: {@see GeocodingService::geocode()} is exercised
 * against a faked HTTP client and an in-memory (array) cache. No database is
 * touched, so no RefreshDatabase trait is needed.
 *
 * The rule under test: for any address, the first geocode issues at most one
 * Nominatim request carrying the configured identifying User-Agent, and every
 * subsequent geocode of the same (normalised) address returns the cached
 * result without issuing a further request.
 *
 * Note on isolation: {@see Http::fake()} records requests for the lifetime of
 * a test method — the recorder is NOT reset between Eris iterations. To make
 * per-iteration counting meaningful, each iteration re-fakes `Http` (which
 * resets the recorder to zero) and flushes the cache, so
 * {@see Http::assertSentCount()} reflects only the calls made in that
 * iteration.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy.
 */
class GeocodeCachingTest extends PbtTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A configured, identifying User-Agent is required for any request to
        // be issued at all; a deterministic base URI keeps the fake simple; the
        // array store gives a real, lock-capable cache with no DB dependency.
        config([
            'services.nominatim.user_agent' => 'TestAgent/1.0 (test@example.com)',
            'services.nominatim.base_uri' => 'https://nominatim.test',
            'cache.default' => 'array',
        ]);
    }

    /**
     * Property 9: Geocode caching and identifying User-Agent — for a generated
     * address, the first lookup issues exactly one HTTP request carrying the
     * configured User-Agent, and repeat lookups of the same address (including
     * cosmetically normalised variants) are served from cache with no further
     * request.
     *
     * **Validates: Requirements 4.4**
     */
    // Feature: event-experience-polish, Property 9: Geocode caching and identifying User-Agent
    public function test_geocode_caches_by_normalised_address_and_sends_identifying_user_agent(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $service = new GeocodingService;

        $this->forAll(
            Generator\elements(
                '10 Downing St, London',
                'Eiffel Tower, Paris',
                'Brandenburg Gate, Berlin',
                'Colosseum, Rome',
                'Statue of Liberty, New York',
            ),
        )
            ->then(function (string $address) use ($service): void {
                // Isolate this iteration: fresh cache and a fresh HTTP recorder
                // (Http::fake resets the recorded-request count to zero).
                Cache::flush();
                Http::fake([
                    '*' => Http::response([
                        ['lat' => '51.5', 'lon' => '-0.12', 'display_name' => 'X'],
                    ], 200),
                ]);

                // First lookup: a cold cache — must resolve via HTTP.
                $first = $service->geocode($address);

                $this->assertInstanceOf(
                    GeocodeResult::class,
                    $first,
                    sprintf('first geocode of "%s" should resolve to a GeocodeResult', $address),
                );

                // Second lookup of the exact same address — must come from cache.
                $service->geocode($address);

                // Third lookup of a cosmetically different spelling (extra
                // whitespace + different case) — the cache key is normalised, so
                // this must also be a cache hit and issue no request.
                $variant = '  '.strtoupper($address).'   ';
                $service->geocode($variant);

                // Across all three same-address lookups, exactly one HTTP
                // request was issued; the cache served the rest.
                Http::assertSentCount(1);

                // The single request carried the configured identifying UA.
                Http::assertSent(fn ($request) => $request->hasHeader(
                    'User-Agent',
                    'TestAgent/1.0 (test@example.com)',
                ));
            });
    }
}
