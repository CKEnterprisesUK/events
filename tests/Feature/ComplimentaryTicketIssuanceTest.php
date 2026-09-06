<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 21.1.
 *
 * Covers complimentary ("comp") ticket issuance in OrderController /
 * CompTicketService: an Admin issues comps for an Event, creating a confirmed
 * zero-money Order with one Ticket per comp ticket, generating the QR and
 * enqueueing the ticket email — with no payment. Comps consume capacity through
 * the same CapacityReservationService path as a paid sale, counting against
 * both the Ticket_Type and the Event's overall capacity and never overselling.
 *
 * Requirements: 18.1 (Order + Ticket rows without payment), 18.2 (QR generated
 * + ticket email enqueued), 18.3 (comps count against Event overall capacity
 * and Ticket_Type capacity). Admin gating (3.4) and free/no-charge invariants
 * (design Property 18) are exercised alongside.
 *
 * Runs against the real MySQL test DB so the FOR UPDATE capacity reserve/commit
 * behaves as in production. Mail is never really sent — the container binds the
 * FakeTicketMailer in the testing environment; the queue is faked here.
 */
class ComplimentaryTicketIssuanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A published Event with a single paid Ticket_Type, owned by the given
     * Admin's Company.
     *
     * @return array{0: Event, 1: TicketType}
     */
    private function eventWithType(User $admin, int $capacity = 100, int $priceMinor = 2500): array
    {
        $event = Event::factory()
            ->for($admin->company)
            ->published()
            ->create(['capacity' => $capacity]);

        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => $priceMinor,
            'capacity' => $capacity,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        return [$event, $type];
    }

    // ---- Happy path: Admin issues comps -------------------------------------

    public function test_admin_issues_comps_creating_a_confirmed_zero_money_order_with_tickets(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        [$event, $type] = $this->eventWithType($admin, capacity: 100, priceMinor: 2500);

        $response = $this->actingAs($admin)->post(route('dashboard.events.comp', $event), [
            'recipient_name' => 'Guest of Honour',
            'recipient_email' => 'guest@example.com',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 3],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        // Requirement 18.1 — exactly one confirmed Order was created, with no
        // payment (no Stripe session/charge). It is free_confirmed.
        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(Order::STATUS_FREE_CONFIRMED, $order->status);
        $this->assertNull($order->stripe_session_id);
        $this->assertNull($order->stripe_charge_id);
        $this->assertSame('Guest of Honour', $order->customer_name);
        $this->assertSame('guest@example.com', $order->customer_email);

        // Requirement 18.1 — one Ticket row per comp ticket.
        $this->assertSame(3, Ticket::withoutGlobalScopes()->where('order_id', $order->id)->count());

        // design Property 18 — a comp incurs no money: every money field is zero
        // regardless of the Ticket_Type price.
        $this->assertSame(0, $order->ticket_subtotal_minor);
        $this->assertSame(0, $order->booking_fee_minor);
        $this->assertSame(0, $order->application_fee_minor);
        $this->assertSame(0, $order->order_total_minor);
        $this->assertTrue($order->isFree());

        // Requirement 18.2 — the QR is generated (Order fulfilled) and the
        // ticket email is enqueued exactly once on the DB queue.
        $this->assertNotNull($order->fulfilled_at);
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    public function test_issued_comps_consume_ticket_type_and_event_capacity(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        [$event, $type] = $this->eventWithType($admin, capacity: 10);

        $this->actingAs($admin)->post(route('dashboard.events.comp', $event), [
            'recipient_name' => 'Comp Recipient',
            'recipient_email' => 'comp@example.com',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 4],
            ],
        ])->assertRedirect();

        // Requirement 18.3 — comps count against the Ticket_Type capacity: the
        // held units are committed reserved → sold, leaving no dangling
        // reservation.
        $fresh = $type->fresh();
        $this->assertSame(4, $fresh->sold_count);
        $this->assertSame(0, $fresh->reserved_count);
    }

    // ---- Capacity is never oversold -----------------------------------------

    public function test_comp_request_exceeding_capacity_is_rejected_without_oversell(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        // Ticket_Type capacity 5; 3 already sold, so only 2 remain.
        $event = Event::factory()->for($admin->company)->published()->create(['capacity' => 5]);
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 5,
            'sold_count' => 3,
            'reserved_count' => 0,
        ]);

        // Requirement 18.3 — requesting 3 comps when only 2 remain is rejected
        // atomically: nothing reserved, no Order/Tickets created, counts
        // unchanged.
        $this->actingAs($admin)->from('/dashboard')
            ->post(route('dashboard.events.comp', $event), [
                'recipient_name' => 'Too Many',
                'recipient_email' => 'toomany@example.com',
                'items' => [
                    ['ticket_type_id' => $type->id, 'quantity' => 3],
                ],
            ])->assertSessionHasErrors('items');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, Ticket::withoutGlobalScopes()->count());

        $fresh = $type->fresh();
        $this->assertSame(3, $fresh->sold_count);
        $this->assertSame(0, $fresh->reserved_count);

        Queue::assertNothingPushed();
    }

    public function test_comp_request_exceeding_overall_event_capacity_is_rejected(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        // Overall Event capacity 3, but the single Ticket_Type has room for 100.
        $event = Event::factory()->for($admin->company)->published()->create(['capacity' => 3]);
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        // Requirement 18.3 / 5.6 — 4 comps exceed the Event's overall capacity
        // of 3; rejected with nothing issued.
        $this->actingAs($admin)->from('/dashboard')
            ->post(route('dashboard.events.comp', $event), [
                'recipient_name' => 'Over Event Cap',
                'recipient_email' => 'overcap@example.com',
                'items' => [
                    ['ticket_type_id' => $type->id, 'quantity' => 4],
                ],
            ])->assertSessionHasErrors('items');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    // ---- Role gating ---------------------------------------------------------

    public function test_non_admin_roles_are_forbidden_from_issuing_comps(): void
    {
        Queue::fake();

        // The Owner, as the account superuser, may issue comps; only the
        // non-Admin, non-Owner roles are forbidden here.
        foreach (['accountant', 'scanner'] as $roleState) {
            $user = User::factory()->{$roleState}()->create();
            [$event, $type] = $this->eventWithType($user);

            $this->actingAs($user)->post(route('dashboard.events.comp', $event), [
                'recipient_name' => 'Nope',
                'recipient_email' => 'nope@example.com',
                'items' => [
                    ['ticket_type_id' => $type->id, 'quantity' => 1],
                ],
            ])->assertForbidden();
        }

        // No comp Order was created and no email enqueued for any denied role.
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    // ---- Tenant isolation ----------------------------------------------------

    public function test_admin_cannot_issue_comps_against_another_companys_event(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();

        // An Event belonging to a different Company — invisible under the
        // dashboard tenant scope, so route-model binding 404s. (Requirement 1.5)
        $foreignEvent = Event::factory()->published()->create(['capacity' => 100]);
        $foreignType = TicketType::factory()->forEvent($foreignEvent)->create([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        $this->actingAs($admin)->post(route('dashboard.events.comp', $foreignEvent), [
            'recipient_name' => 'Cross Tenant',
            'recipient_email' => 'cross@example.com',
            'items' => [
                ['ticket_type_id' => $foreignType->id, 'quantity' => 1],
            ],
        ])->assertNotFound();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }
}
