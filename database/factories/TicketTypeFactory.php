<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    /**
     * Define the model's default state.
     *
     * Defaults to a paid Ticket_Type with an open sale window and no sold or
     * reserved units yet, belonging to a freshly created Event (and that
     * Event's Company). Override `company_id`/`event_id`/`capacity`, or use the
     * `free()`/`soldOut()` states, for other shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();

        return [
            'company_id' => $company,
            'event_id' => Event::factory()->for($company),
            'name' => fake()->words(2, true),
            'price_minor' => fake()->numberBetween(500, 50_000),
            'capacity' => fake()->numberBetween(10, 1_000),
            'capacity_mode' => TicketType::MODE_CAPPED,
            'sold_count' => 0,
            'reserved_count' => 0,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addMonth(),
        ];
    }

    /**
     * A free Ticket_Type (price 0). (Requirement 6.3)
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_minor' => 0,
        ]);
    }

    /**
     * A Ticket_Type belonging to the given Event (and its Company).
     */
    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $event->company_id,
            'event_id' => $event->id,
        ]);
    }

    /**
     * A shared-pool Ticket_Type with no per-type capacity. Availability is
     * governed solely by the event's overall capacity. (Requirements 2.1, 2.3)
     */
    public function sharedPool(): static
    {
        return $this->state(fn () => [
            'capacity_mode' => TicketType::MODE_SHARED_POOL,
            'capacity' => null,
        ]);
    }

    /**
     * A fully sold-out Ticket_Type (sold_count == capacity).
     */
    public function soldOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'sold_count' => $attributes['capacity'],
        ]);
    }
}
