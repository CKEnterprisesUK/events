<?php

namespace App\Services;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues complimentary ("comp") tickets for an Event on behalf of an Admin.
 * (Requirement 18: Complimentary and Free Ticket Issuance)
 *
 * A comp is a confirmed Order created without payment: the Admin picks
 * Ticket_Types and quantities and the Platform creates one Ticket per comp
 * ticket, generates the QR, and enqueues the ticket email — exactly the shape
 * of a free-only checkout, but initiated from the dashboard rather than by a
 * Customer paying zero. (Requirements 18.1, 18.2)
 *
 * Because a comp is money-free by definition, every money field is zeroed
 * through the {@see FeeCalculationService} free path (subtotal 0 ⇒ all zero,
 * regardless of the Ticket_Type price or the Company's fee mode) and no Stripe
 * charge is ever created. The Order is confirmed as `free_confirmed`, the same
 * status the checkout free path uses, so all the confirmed-Order machinery
 * (scanning, cancellation, reporting) treats a comp like any free order.
 * (design Property 18 — free orders incur no money/no charge)
 *
 * Capacity is consumed through the *same* {@see CapacityReservationService}
 * path as a paid sale — reserve, then commit on fulfilment — so a comp counts
 * against both the Ticket_Type capacity and the Event's overall capacity and
 * can never oversell: when the request does not fit, the reservation is
 * rejected atomically with nothing held and no Order created. (Requirements
 * 18.3, 5.6, 6.6, 6.7; design Property 10)
 */
class CompTicketService
{
    public function __construct(
        private readonly CapacityReservationService $capacity,
        private readonly FeeCalculationService $fees,
        private readonly OrderFulfilmentService $fulfilment,
    ) {}

    /**
     * Issue a complimentary Order for the given Event and quantities.
     *
     * @param  Company  $company  The Company that owns the Event (the acting Admin's Company).
     * @param  Event  $event  The Event to issue comps against.
     * @param  array<int, int>  $quantities  Map of Ticket_Type id => quantity (qty > 0).
     * @param  string  $recipientName  The comp recipient's name (stored as the Order's customer_name).
     * @param  string  $recipientEmail  The recipient email the ticket is sent to.
     *
     * @throws InsufficientCapacityException When the request exceeds availability (nothing reserved, no Order created).
     * @throws \InvalidArgumentException When a Ticket_Type does not belong to the Event or quantities are malformed.
     */
    public function issue(
        Company $company,
        Event $event,
        array $quantities,
        string $recipientName,
        string $recipientEmail,
    ): Order {
        // Confirm every requested Ticket_Type belongs to this Event before
        // touching capacity, so a stray/foreign id is rejected up front and the
        // reservation only ever locks this Event's own rows. (Requirement 18.1)
        $this->assertTicketTypesBelongToEvent($event, array_keys($quantities));

        // A comp is money-free by definition: run the fee engine with a zero
        // subtotal so every money field is zero regardless of the Ticket_Type
        // price or the Company's fee mode. (design Property 18)
        $fee = $this->fees->calculate(0, $this->fees->effectivePercent($company), $company->fee_handling_mode);

        // Reserve capacity atomically through the same path a paid sale uses, so
        // a comp counts against the Ticket_Type and overall Event capacity and
        // can never oversell. An over-request throws with nothing held.
        // (Requirements 18.3, 5.6)
        $reservedUntil = $this->capacity->reserve($event, $quantities);

        // Persist the comp Order + its Tickets atomically. If persistence fails,
        // release the just-made reservation so capacity is not stranded.
        try {
            $order = DB::transaction(function () use ($event, $fee, $reservedUntil, $quantities, $recipientName, $recipientEmail): Order {
                $order = Order::create([
                    'event_id' => $event->id,
                    'order_reference' => $this->uniqueOrderReference(),
                    'customer_name' => $recipientName,
                    'customer_email' => $recipientEmail,
                    // Confirmed with no payment — the same status the checkout
                    // free path uses. (Requirements 18.1, 10.9)
                    'status' => Order::STATUS_FREE_CONFIRMED,
                    'reserved_until' => $reservedUntil,
                    ...$fee->toArray(),
                ]);

                // One Ticket per comp ticket. (Requirements 18.1, 10.5)
                foreach ($quantities as $ticketTypeId => $qty) {
                    for ($i = 0; $i < $qty; $i++) {
                        Ticket::create([
                            'order_id' => $order->id,
                            'ticket_type_id' => $ticketTypeId,
                            'status' => Ticket::STATUS_VALID,
                        ]);
                    }
                }

                return $order;
            });
        } catch (\Throwable $e) {
            $this->capacity->release($event, $quantities);

            throw $e;
        }

        // Fulfil now: commit the held capacity reserved → sold, generate the QR,
        // and enqueue the ticket email on the DB queue. Idempotent.
        // (Requirements 18.2, 18.3, 14.1, 14.3)
        $this->fulfilment->fulfil($order);

        return $order;
    }

    /**
     * Reject any requested Ticket_Type id that does not belong to the Event
     * (another Event's or Company's types never match the scoped query), so a
     * comp can only be issued against this Event's own Ticket_Types.
     *
     * @param  array<int, int>  $ticketTypeIds
     */
    private function assertTicketTypesBelongToEvent(Event $event, array $ticketTypeIds): void
    {
        if ($ticketTypeIds === []) {
            throw new \InvalidArgumentException('At least one ticket type is required to issue a complimentary order.');
        }

        $ownedIds = $event->ticketTypes()
            ->whereIn('id', $ticketTypeIds)
            ->pluck('id')
            ->all();

        foreach ($ticketTypeIds as $id) {
            if (! in_array((int) $id, array_map('intval', $ownedIds), true)) {
                throw new \InvalidArgumentException(
                    "Ticket type {$id} does not belong to event {$event->getKey()}."
                );
            }
        }
    }

    /**
     * Generate a Platform-unique Order_Reference, mirroring the checkout path.
     * Uniqueness is also enforced by the UNIQUE index on
     * `orders.order_reference`. (Requirement 10.13)
     */
    private function uniqueOrderReference(): string
    {
        do {
            $reference = strtoupper(Str::random(12));
        } while (Order::withoutGlobalScopes()->where('order_reference', $reference)->exists());

        return $reference;
    }
}
