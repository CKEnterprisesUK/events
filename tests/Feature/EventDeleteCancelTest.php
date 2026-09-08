<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: deletion vs. cancellation of an Event.
 *
 * The rule under test:
 *   - An Event with NO confirmed bookings can be deleted outright.
 *   - An Event that HAS taken bookings can only be cancelled — its Orders are
 *     retained so the organiser can contact customers and arrange refunds via
 *     support. Each action refuses the other's precondition.
 */
class EventDeleteCancelTest extends TestCase
{
    use RefreshDatabase;

    private function adminForCompany(Company $company): User
    {
        return User::factory()->admin()->create(['company_id' => $company->id]);
    }

    public function test_admin_can_delete_an_event_with_no_bookings(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($admin)
            ->delete(route('dashboard.events.destroy', $event))
            ->assertRedirect(route('dashboard.events.index'));

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_delete_is_refused_when_the_event_has_bookings(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create();

        // A confirmed (paid) booking blocks deletion.
        Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        $this->actingAs($admin)
            ->delete(route('dashboard.events.destroy', $event))
            ->assertRedirect(route('dashboard.events.show', $event));

        // The event and its booking survive untouched.
        $this->assertDatabaseHas('events', ['id' => $event->id, 'cancelled_at' => null]);
    }

    public function test_admin_can_cancel_an_event_with_bookings(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create(['is_published' => true]);

        Order::factory()->forEvent($event)->create(['status' => Order::STATUS_FREE_CONFIRMED]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.cancel', $event))
            ->assertRedirect(route('dashboard.events.show', $event));

        $fresh = $event->fresh();
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertTrue($fresh->isCancelled());
        // Cancelling unpublishes the event but keeps every order.
        $this->assertFalse($fresh->isPublished());
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cancel_is_refused_when_the_event_has_no_bookings(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($admin)
            ->post(route('dashboard.events.cancel', $event))
            ->assertRedirect(route('dashboard.events.show', $event));

        $this->assertNull($event->fresh()->cancelled_at);
    }

    public function test_reserved_orders_do_not_count_as_bookings(): void
    {
        // Reserved/expired orders never issued tickets, so an event that only
        // has those can still be deleted.
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create();

        Order::factory()->forEvent($event)->create(['status' => Order::STATUS_RESERVED]);
        Order::factory()->forEvent($event)->create(['status' => Order::STATUS_EXPIRED]);

        $this->assertFalse($event->fresh()->hasBookings());

        $this->actingAs($admin)
            ->delete(route('dashboard.events.destroy', $event))
            ->assertRedirect(route('dashboard.events.index'));

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_non_admin_cannot_delete_or_cancel(): void
    {
        $company = Company::factory()->create();
        $accountant = User::factory()->accountant()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($accountant)
            ->delete(route('dashboard.events.destroy', $event))
            ->assertForbidden();

        $this->actingAs($accountant)
            ->post(route('dashboard.events.cancel', $event))
            ->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_cannot_delete_or_cancel_another_companys_event(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $admin = $this->adminForCompany($companyA);
        $foreign = Event::factory()->for($companyB)->create();

        $this->actingAs($admin)
            ->delete(route('dashboard.events.destroy', $foreign))
            ->assertNotFound();

        $this->assertDatabaseHas('events', ['id' => $foreign->id]);
    }
}
