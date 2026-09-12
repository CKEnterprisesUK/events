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

    /**
     * A paid Order: confirmed via the Stripe webhook and fulfilled. Carries a
     * Platform application fee, the actual Stripe processing fee, and the Stripe
     * linkage a real paid Order would have. (Requirements 12.6, 14.1)
     */
    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            $total = $attributes['order_total_minor'] ?? $attributes['ticket_subtotal_minor'] ?? fake()->numberBetween(1_000, 50_000);

            return [
                'status' => Order::STATUS_PAID,
                'order_total_minor' => $total,
                // Platform skim (~5%) and Stripe's card fee (~1.5% + 20p),
                // both in integer minor units, mirroring live proportions.
                'application_fee_minor' => (int) round($total * 0.05),
                'stripe_fee_minor' => (int) round($total * 0.015) + 20,
                'reserved_until' => null,
                'fulfilled_at' => now()->subDays(fake()->numberBetween(0, 30)),
                'stripe_session_id' => 'cs_'.fake()->unique()->bothify('################'),
                'stripe_charge_id' => 'ch_'.fake()->unique()->bothify('################'),
                'stripe_payment_intent_id' => 'pi_'.fake()->unique()->bothify('################'),
            ];
        });
    }

    /**
     * A free Order confirmed at checkout (no payment) and fulfilled. The free
     * counterpart to {@see paid()}. (Requirement 10.9)
     */
    public function freeConfirmed(): static
    {
        return $this->free()->state(fn (array $attributes) => [
            'status' => Order::STATUS_FREE_CONFIRMED,
            'reserved_until' => null,
            'fulfilled_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ]);
    }

    /**
     * A paid Order that has been partially refunded — still `paid` with valid
     * Tickets, but with a non-zero `refunded_total_minor` below the full total.
     * (Requirement 17.2)
     */
    public function partiallyRefunded(): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'refunded_total_minor' => (int) round(($attributes['order_total_minor'] ?? 0) * fake()->randomFloat(2, 0.1, 0.6)),
        ]);
    }

    /**
     * A fully refunded Order: cumulative refunds have reached the full total,
     * flipping it to the terminal `refunded` state. (Requirement 17.2)
     */
    public function refunded(): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'status' => Order::STATUS_REFUNDED,
            'refunded_total_minor' => $attributes['order_total_minor'] ?? 0,
        ]);
    }

    /**
     * A confirmed Order that has been scanned in at the door. Stamps
     * `scanned_at`; pair with `scanned_by` when a scanner User exists.
     */
    public function scanned(): static
    {
        return $this->state(fn (array $attributes) => [
            'scanned_at' => now()->subHours(fake()->numberBetween(1, 72)),
        ]);
    }
}
