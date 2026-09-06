<?php

namespace Tests\Feature;

use App\Services\Geocoding\GeocodeResult;
use App\Services\GeocodingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature: event-experience-polish
 *
 * Covers task 5.4 — feature/unit test for App\Services\GeocodingService, the
 * config-driven Nominatim wrapper.
 *
 * Requirements: 4.4 (cache by normalised address, no second HTTP call on a
 * cache hit, identifying User-Agent on every request), 4.5 (null on
 * miss/error/timeout, and null without any request when the User-Agent is
 * unconfigured).
 */
class GeocodingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Array cache (from phpunit.xml CACHE_STORE=array) supports Cache::lock,
        // which the service uses to serialise concurrent bursts.
        Cache::flush();

        config([
            'services.nominatim.user_agent' => 'TestAgent/1.0 (test@example.com)',
            'services.nominatim.base_uri' => 'https://nominatim.test',
        ]);
    }

    private function service(): GeocodingService
    {
        return new GeocodingService;
    }

    public function test_successful_geocode_returns_result(): void
    {
        Http::fake([
            '*' => Http::response([
                ['lat' => '51.5074', 'lon' => '-0.1278', 'display_name' => 'London'],
            ], 200),
        ]);

        $result = $this->service()->geocode('London');

        $this->assertInstanceOf(GeocodeResult::class, $result);
        $this->assertEqualsWithDelta(51.5074, $result->latitude, 0.0001);
        $this->assertEqualsWithDelta(-0.1278, $result->longitude, 0.0001);
        $this->assertSame('London', $result->displayName);
    }

    public function test_cached_address_issues_no_second_http_call(): void
    {
        Http::fake([
            '*' => Http::response([
                ['lat' => '51.5074', 'lon' => '-0.1278', 'display_name' => 'London'],
            ], 200),
        ]);

        $this->service()->geocode('London');
        $this->service()->geocode('London');

        Http::assertSentCount(1);
    }

    public function test_request_carries_user_agent_header(): void
    {
        Http::fake([
            '*' => Http::response([
                ['lat' => '1.0', 'lon' => '2.0', 'display_name' => 'X'],
            ], 200),
        ]);

        $this->service()->geocode('X');

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'TestAgent/1.0 (test@example.com)'));
    }

    public function test_empty_result_yields_null(): void
    {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $this->assertNull($this->service()->geocode('Nowhere at all'));
    }

    public function test_error_response_yields_null(): void
    {
        Http::fake([
            '*' => Http::response('server error', 500),
        ]);

        $this->assertNull($this->service()->geocode('London'));
    }

    public function test_connection_exception_yields_null(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertNull($this->service()->geocode('London'));
    }

    public function test_missing_user_agent_yields_null_and_sends_nothing(): void
    {
        config(['services.nominatim.user_agent' => null]);

        Http::fake();

        $this->assertNull($this->service()->geocode('London'));

        Http::assertNothingSent();
    }
}
