<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Segmentation / data-segregation check for customer data.
 *
 * Customer PII (customer_name, customer_email) lives on the Order model, which
 * is Company-owned via the BelongsToCompany trait and the global TenantScope.
 * These tests assert that one Company can never read, update, or delete another
 * Company's customer records, and that with no tenant resolved no customer data
 * is reachable at all. (Requirements 1.4, 1.5, 1.7)
 */
class CustomerDataSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        // Never let a resolved tenant leak into the next test on this worker.
        $this->tenant()->clear();

        parent::tearDown();
    }

    public function test_reads_are_limited_to_the_active_companys_customers(): void
    {
        // Requirement 1.4 — reads only surface the resolved Company's rows.
        $companyA = Company::factory()->create(['slug' => 'company-a']);
        $companyB = Company::factory()->create(['slug' => 'company-b']);

        // No tenant is active during setup, so factory inserts land for the
        // named company. forEvent() keeps company_id and event_id aligned. The
        // scope filters reads/writes, not these unscoped inserts.
        $eventA = \App\Models\Event::factory()->for($companyA)->create();
        $eventB = \App\Models\Event::factory()->for($companyB)->create();

        Order::factory()->forEvent($eventA)->create([
            'customer_email' => 'alice@company-a.test',
        ]);
        Order::factory()->forEvent($eventB)->create([
            'customer_email' => 'bob@company-b.test',
        ]);

        $this->tenant()->setCompany($companyA);

        $emails = Order::query()->pluck('customer_email');

        $this->assertCount(1, $emails);
        $this->assertContains('alice@company-a.test', $emails);
        $this->assertNotContains('bob@company-b.test', $emails);
    }

    public function test_another_companys_customer_record_is_not_found(): void
    {
        // Requirement 1.5 — a foreign row never matches under the resolved tenant.
        $companyA = Company::factory()->create(['slug' => 'company-a']);
        $companyB = Company::factory()->create(['slug' => 'company-b']);

        $eventB = \App\Models\Event::factory()->for($companyB)->create();
        $foreign = Order::factory()->forEvent($eventB)->create([
            'customer_email' => 'bob@company-b.test',
        ]);

        $this->tenant()->setCompany($companyA);

        $this->assertNull(Order::query()->find($foreign->id));
        $this->assertNull(Order::query()->where('customer_email', 'bob@company-b.test')->first());
    }

    public function test_cross_company_update_and_delete_affect_no_rows(): void
    {
        // Requirement 1.5 — writes targeting a foreign row change nothing.
        $companyA = Company::factory()->create(['slug' => 'company-a']);
        $companyB = Company::factory()->create(['slug' => 'company-b']);

        $eventB = \App\Models\Event::factory()->for($companyB)->create();
        $foreign = Order::factory()->forEvent($eventB)->create([
            'customer_name' => 'Bob Original',
            'customer_email' => 'bob@company-b.test',
        ]);

        $this->tenant()->setCompany($companyA);

        $updated = Order::query()
            ->where('id', $foreign->id)
            ->update(['customer_name' => 'Hijacked']);
        $deleted = Order::query()->where('id', $foreign->id)->delete();

        $this->assertSame(0, $updated);
        $this->assertSame(0, $deleted);

        // The foreign record is untouched.
        $fresh = Order::withoutGlobalScopes()->find($foreign->id);
        $this->assertNotNull($fresh);
        $this->assertSame('Bob Original', $fresh->customer_name);
    }

    public function test_new_customer_orders_belong_to_the_active_company(): void
    {
        // Requirement 1.1 — company_id is auto-filled from the resolved tenant.
        $companyA = Company::factory()->create(['slug' => 'company-a']);
        $event = \App\Models\Event::factory()->for($companyA)->create();

        $this->tenant()->setCompany($companyA);

        $order = Order::factory()->create([
            'event_id' => $event->id,
            'company_id' => null, // force auto-fill from the tenant context
            'customer_email' => 'carol@company-a.test',
        ]);

        $this->assertSame($companyA->id, $order->company_id);
    }

    public function test_no_active_tenant_exposes_no_customer_data(): void
    {
        // Requirement 1.7 — with no resolved Company the scope forces 1 = 0.
        $companyA = Company::factory()->create(['slug' => 'company-a']);
        $eventA = \App\Models\Event::factory()->for($companyA)->create();
        Order::factory()->forEvent($eventA)->create([
            'customer_email' => 'alice@company-a.test',
        ]);

        // No tenant set.
        $this->assertCount(0, Order::query()->get());
    }
}
