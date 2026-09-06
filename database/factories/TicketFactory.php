<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /**
     * Define the model's default state.
     *
     * Defaults to a `valid` Ticket belonging to a freshly created Order and
     * Ticket_Type. Prefer `forOrder()`/`forTicketType()` so the Ticket shares
     * its Order's Company and event context.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'company_id' => fn (array $attributes) => Order::withoutGlobalScopes()->find($attributes['order_id'])->company_id,
            'ticket_type_id' => TicketType::factory(),
            'status' => Ticket::STATUS_VALID,
        ];
    }

    /**
     * A Ticket belonging to the given Order (and its Company).
     */
    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $order->company_id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * A Ticket for the given Ticket_Type.
     */
    public function forTicketType(TicketType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'ticket_type_id' => $type->id,
        ]);
    }

    /**
     * A voided Ticket. (Requirement 17.3)
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Ticket::STATUS_VOIDED,
        ]);
    }
}
