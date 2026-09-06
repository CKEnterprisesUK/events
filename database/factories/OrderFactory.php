<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * Defaults to a `reserved` Order with a 900s window, an absorbed fee mode,
     * and a Platform-unique `order_reference`, belonging to a freshly created
     * Event (and that Event's Company). Override `company_id`/`event_id`, or
     * use the state helpers, for other shapes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory();
        $subtotal = fake()->numberBetween(1_000, 50_000);

        return [
            'company_id' => $company,
            'event_id' => Event::factory()->for($company),
            'order_reference' => strtoupper(Str::random(12)),
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'status' => Order::STATUS_RESERVED,
            'ticket_subtotal_minor' => $subtotal,
            'booking_fee_minor' => 0,
            'application_fee_minor' => 0,
            'order_total_minor' => $subtotal,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'reserved_until' => now()->addSeconds(900),
            'stripe_session_id' => null,
            'stripe_charge_id' => null,
            'stripe_payment_intent_id' => null,
            'scanned_at' => null,
            'scanned_by' => null,
        ];
    }

    /**
     * An Order belonging to the given Event (and its Company).
     */
    public function forEvent(Event $event): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $event->company_id,
            'event_id' => $event->id,
        ]);
    }

    /**
     * A reserved Order whose 900s window has already elapsed.
     */
    public function expiredWindow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Order::STATUS_RESERVED,
            'reserved_until' => now()->subSeconds(1),
        ]);
    }

    /**
     * A free-only Order: all money zero. (Requirements 10.9, 13.7)
     */
    public function free(): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_subtotal_minor' => 0,
            'booking_fee_minor' => 0,
            'application_fee_minor' => 0,
            'order_total_minor' => 0,
        ]);
    }
}
