<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderConsent>
 */
class OrderConsentFactory extends Factory
{
    protected $model = OrderConsent::class;

    /**
     * Define the model's default state.
     *
     * Defaults to an accepted `terms` consent belonging to a freshly created
     * Order (and its Company). Prefer `forOrder()` so the consent shares its
     * Order's Company context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'company_id' => fn (array $attributes) => Order::withoutGlobalScopes()->find($attributes['order_id'])->company_id,
            'consent_key' => 'terms',
            'accepted' => true,
            'captured_at' => now(),
        ];
    }

    /**
     * A consent record belonging to the given Order (and its Company).
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $order->company_id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * A consent with a specific key and acceptance state.
     */
    public function key(string $consentKey, bool $accepted = true): static
    {
        return $this->state(fn (array $attributes) => [
            'consent_key' => $consentKey,
            'accepted' => $accepted,
        ]);
    }
}
