<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Services\Geocoding\GeocodeResult;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-experience-polish
 *
 * Task 7.5 — event geocode/location save behaviour in EventController's
 * store()/update() via applyLocationAndPoster().
 *
 * Requirements:
 *  - 4.2: saving an in-person event with an address geocodes it synchronously
 *    and stores the resulting coordinates alongside the address.
 *  - 4.3: an unchanged address is not re-geocoded so a manually dragged pin
 *    (stored coordinates) is preserved.
 *  - 4.5: a failed/no-match geocode saves the event without coordinates and
 *    warns the manager.
 *
 * Geocoding is controlled by binding a fake {@see GeocodingService} into the
 * container, so no HTTP is issued and the outcome is deterministic.
 */
class EventGeocodeLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a GeocodingService whose geocode() always returns $result.
     */
    private function fakeGeocoder(?GeocodeResult $result): void
    {
        $this->instance(GeocodingService::class, new class($result) extends GeocodingService
        {
            public function __construct(private ?GeocodeResult $result) {}

            public function geocode(string $address): ?GeocodeResult
            {
                return $this->result;
            }
        });
    }

    public function test_in_person_save_with_address_stores_geocoded_coordinates(): void
    {
        // Requirement 4.2 — a successful geocode stores the coordinates.
        $this->fakeGeocoder(new GeocodeResult(51.5, -0.12, 'London'));

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/dashboard/events', [
            'name' => 'Mapped Event',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'address' => 'Somewhere',
        ])->assertRedirect();

        $this->assertDatabaseHas('events', [
            'company_id' => $admin->company_id,
            'name' => 'Mapped Event',
            'address' => 'Somewhere',
        ]);

        $event = Event::withoutGlobalScopes()->where('name', 'Mapped Event')->first();

        // decimal:7 casts return strings — compare as floats with a delta.
        $this->assertEqualsWithDelta(51.5, (float) $event->latitude, 0.0000001);
        $this->assertEqualsWithDelta(-0.12, (float) $event->longitude, 0.0000001);
    }

    public function test_geocode_failure_saves_without_coordinates_and_flashes_warning(): void
    {
        // Requirement 4.5 — no match: save without coordinates and warn.
        $this->fakeGeocoder(null);

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->from('/dashboard/events')
            ->post('/dashboard/events', [
                'name' => 'Unlocatable Event',
                'location_mode' => Event::LOCATION_IN_PERSON,
                'address' => 'Nowhere at all',
            ]);

        $response->assertSessionHas('geocode_warning');

        $this->assertDatabaseHas('events', [
            'company_id' => $admin->company_id,
            'name' => 'Unlocatable Event',
            'address' => 'Nowhere at all',
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    public function test_unchanged_address_does_not_re_geocode(): void
    {
        // Requirement 4.3/4.4 — an unchanged address is not re-geocoded, and a
        // previously stored (dragged) pin is preserved.
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))->create([
            'name' => 'Pinned Event',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'address' => 'X',
            'latitude' => 10,
            'longitude' => 20,
        ]);

        // A geocoder that fails the test if it is ever called.
        $this->instance(GeocodingService::class, new class extends GeocodingService
        {
            public function geocode(string $address): ?GeocodeResult
            {
                throw new \RuntimeException('geocode() must not be called for an unchanged address');
            }
        });

        $this->actingAs($admin)->put("/dashboard/events/{$event->id}", [
            'name' => 'Pinned Event',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'address' => 'X',
        ])->assertRedirect(route('dashboard.events.show', $event));

        $fresh = $event->fresh();
        $this->assertEqualsWithDelta(10.0, (float) $fresh->latitude, 0.0000001);
        $this->assertEqualsWithDelta(20.0, (float) $fresh->longitude, 0.0000001);
    }

    public function test_online_save_clears_map_fields(): void
    {
        // Requirement 4.5 (online branch) — switching to online clears the map.
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))->create([
            'name' => 'Going Online',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'address' => 'Somewhere real',
            'latitude' => 12.3456789,
            'longitude' => 65.4321,
        ]);

        // Geocoding must not run for an online save.
        $this->fakeGeocoder(new GeocodeResult(1.0, 2.0));

        $this->actingAs($admin)->put("/dashboard/events/{$event->id}", [
            'name' => 'Going Online',
            'location_mode' => Event::LOCATION_ONLINE,
        ])->assertRedirect(route('dashboard.events.show', $event));

        $fresh = $event->fresh();
        $this->assertSame(Event::LOCATION_ONLINE, $fresh->location_mode);
        $this->assertNull($fresh->address);
        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->longitude);
    }
}
