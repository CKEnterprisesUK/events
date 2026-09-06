<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * Defaults to an unpublished Event belonging to a freshly created Company,
     * with an overall capacity set. Use the `published()`/`unpublished()`
     * states, or override `company_id`/`capacity`, for other shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'venue' => fake()->address(),
            'starts_at' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'capacity' => fake()->numberBetween(10, 1000),
            'is_published' => false,
            'primary_colour' => null,
            'logo_path' => null,
        ];
    }

    /**
     * A published Event whose page is available to Customers. (5.4)
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
        ]);
    }

    /**
     * An unpublished Event that blocks Customer view/purchase. (5.5)
     */
    public function unpublished(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }

    /**
     * An Event with no overall capacity limit. (5.2)
     */
    public function unlimitedCapacity(): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => null,
        ]);
    }
}
