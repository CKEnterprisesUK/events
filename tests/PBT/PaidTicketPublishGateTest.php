<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the payment-readiness publish gate.
 *
 * Uses the Eris library with a minimum of 100 iterations, per the design's
 * Testing Strategy. Runs against the real MySQL test database.
 *
 * The rule under test (in {@see Event::publishBlockers()}): when an Event has
 * its base prerequisites met (a start date and at least one Ticket_Type), the
 * `payments` blocker is present if and only if the Event sells at least one
 * PAID Ticket_Type AND the owning Company cannot take card payments (no
 * connected, charges-enabled Stripe account). A free-only Event never needs
 * Stripe; a Stripe-ready Company clears the blocker. While the blocker is
 * present, `isPublishable()` is false. (Requirements 11.1, 11.5, 12.1)
 */
class PaidTicketPublishGateTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // Feature: event-ticketing-platform, payment-readiness publish gate
    public function test_paid_tickets_require_a_stripe_ready_company_to_publish(): void
    {
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Whether the Event's ticket type is paid (else free).
            Generator\bool(),
            // Whether the owning Company can take card payments.
            Generator\bool(),
        )
            ->then(function (bool $paidTicket, bool $stripeReady): void {
                $company = $stripeReady
                    ? Company::factory()->stripeReady()->create()
                    : Company::factory()->create();

                // A fully valid Event otherwise: start date set, and (below) a
                // single ticket type, so only the payments rule is in play.
                $event = Event::factory()->for($company)->create([
                    'starts_at' => now()->addWeek(),
                ]);

                app(TenantContext::class)->setCompany($company);

                $type = TicketType::factory()->forEvent($event);
                ($paidTicket ? $type : $type->free())->create();

                $event->refresh();

                $blockers = $event->publishBlockers();

                $expectPaymentsBlocker = $paidTicket && ! $stripeReady;

                $this->assertSame(
                    $expectPaymentsBlocker,
                    array_key_exists('payments', $blockers),
                    sprintf(
                        "'payments' blocker presence should track paid && !stripeReady (paid=%s, stripeReady=%s)",
                        $paidTicket ? 'true' : 'false',
                        $stripeReady ? 'true' : 'false',
                    ),
                );

                // The base prerequisites are always met here, so the Event is
                // publishable iff the payments blocker is absent.
                $this->assertSame(
                    ! $expectPaymentsBlocker,
                    $event->isPublishable(),
                    'isPublishable() should be false exactly when the payments blocker is present',
                );
            });
    }
}
