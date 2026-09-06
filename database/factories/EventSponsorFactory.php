<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventSponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSponsor>
 */
class EventSponsorFactory extends Factory
{
    protected $model = EventSponsor::class;

    /**
     * Define the model's default state.
     *
     * Defaults to a store-page-only sponsor (not printed on the ticket) with a
     * name, website and bio, belonging to a freshly created Event (and that
     * Event's Company). Use `onTicket()` for a ticket-printed sponsor or
     * `forEvent()` to attach to an existing Event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'event_id' => Event::factory()->for($company),
            'image_path' => 'branding/sponsors/'.fake()->uuid().'.png',
            'name' => fake()->company(),
            'website_url' => fake()->url(),
            'bio' => fake()->sentence(),
            'on_ticket' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * A sponsor whose logo is printed on the ticket PDF.
     */
    public function onTicket(): static
    {
        return $this->state(fn () => ['on_ticket' => true]);
    }

    /**
     * A bare sponsor: just a logo, with no store-page name/website/bio.
     */
    public function logoOnly(): static
    {
        return $this->state(fn () => [
            'name' => null,
            'website_url' => null,
            'bio' => null,
        ]);
    }

    /**
     * A sponsor belonging to the given Event (and its Company).
     */
    public function forEvent(Event $event): static
    {
        return $this->state(fn () => [
            'company_id' => $event->company_id,
            'event_id' => $event->id,
        ]);
    }
}
