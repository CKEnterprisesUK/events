<?php

namespace Tests\Feature;

use App\Jobs\AnonymiseExpiredCustomerDataJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\GdprService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — customer-data retention.
 *
 * Covers the automated retention sweep (AnonymiseExpiredCustomerDataJob +
 * `customers:anonymise-expired`), the per-Company retention window on
 * {@see Company}, and the retention/marketing additions to the GDPR export.
 *
 * The sweep anonymises a Customer's identifiable personal data (name/email)
 * once that Customer's LAST order has aged past the Company's retention window,
 * while RETAINING the transactional records — the same scrub as a manual GDPR
 * anonymise, applied automatically. Because it deletes personal data, the tests
 * pin down exactly WHEN it fires and, crucially, when it must NOT.
 */
class CustomerDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function job(): AnonymiseExpiredCustomerDataJob
    {
        return new AnonymiseExpiredCustomerDataJob;
    }

    private function runSweep(): int
    {
        return $this->job()->handle(app(GdprService::class), app(AuditLogger::class));
    }

    /**
     * Create a confirmed Order for the given Company/Event, Customer email, and
     * explicit creation time so the test controls the "age" of the customer.
     */
    private function orderAt(Event $event, string $email, \DateTimeInterface $createdAt, string $name = 'Casey Customer'): Order
    {
        return Order::factory()->forEvent($event)->create([
            'customer_name' => $name,
            'customer_email' => $email,
            'status' => Order::STATUS_PAID,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    // ---- Company retention window --------------------------------------------

    public function test_effective_retention_defaults_to_24_months_when_unset(): void
    {
        $company = Company::factory()->create(['retention_months' => null]);

        $this->assertSame(Company::RETENTION_MONTHS_DEFAULT, $company->effectiveRetentionMonths());
        $this->assertSame(24, $company->effectiveRetentionMonths());
    }

    public function test_effective_retention_is_floored_and_capped(): void
    {
        // Even a stored value below the floor / above the ceiling is clamped, so
        // a bad row can never drive the sweep outside the safe band.
        $tooSmall = Company::factory()->create(['retention_months' => 1]);
        $tooLarge = Company::factory()->create(['retention_months' => 5000]);

        $this->assertSame(Company::RETENTION_MONTHS_MIN, $tooSmall->effectiveRetentionMonths());
        $this->assertSame(Company::RETENTION_MONTHS_MAX, $tooLarge->effectiveRetentionMonths());
    }

    // ---- The sweep anonymises only genuinely-expired customers ---------------

    public function test_customer_past_the_window_is_anonymised_but_transactional_records_kept(): void
    {
        $company = Company::factory()->create(['retention_months' => 24]);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $order = $this->orderAt($event, 'old@example.com', now()->subMonths(25), name: 'Old Customer');
        $reference = $order->order_reference;
        $subtotal = $order->ticket_subtotal_minor;

        $anonymised = $this->runSweep();

        $this->assertSame(1, $anonymised);

        $order->refresh();
        // Personal data scrubbed.
        $this->assertSame(GdprService::ANONYMISED_NAME, $order->customer_name);
        $this->assertSame(GdprService::ANONYMISED_EMAIL, $order->customer_email);
        // Transactional records retained for reconciliation.
        $this->assertSame($reference, $order->order_reference);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame($subtotal, $order->ticket_subtotal_minor);
    }

    public function test_customer_inside_the_window_is_left_untouched(): void
    {
        $company = Company::factory()->create(['retention_months' => 24]);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $order = $this->orderAt($event, 'recent@example.com', now()->subMonths(23), name: 'Recent Customer');

        $this->assertSame(0, $this->runSweep());

        $order->refresh();
        $this->assertSame('Recent Customer', $order->customer_name);
        $this->assertSame('recent@example.com', $order->customer_email);
    }

    public function test_customer_with_one_recent_order_is_not_anonymised_even_if_older_orders_expired(): void
    {
        // Expiry is driven by the customer's LAST order: a single fresh order
        // keeps ALL their orders intact, so an active repeat customer is never
        // scrubbed mid-relationship.
        $company = Company::factory()->create(['retention_months' => 24]);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $oldOrder = $this->orderAt($event, 'loyal@example.com', now()->subMonths(30), name: 'Loyal Customer');
        $recentOrder = $this->orderAt($event, 'loyal@example.com', now()->subMonths(2), name: 'Loyal Customer');

        $this->assertSame(0, $this->runSweep());

        $oldOrder->refresh();
        $recentOrder->refresh();
        // The ancient order is NOT scrubbed because the customer is still active.
        $this->assertSame('loyal@example.com', $oldOrder->customer_email);
        $this->assertSame('loyal@example.com', $recentOrder->customer_email);
    }

    public function test_each_company_uses_its_own_window(): void
    {
        // Same customer age, different windows: the short-window company scrubs,
        // the long-window company keeps.
        $shortCompany = Company::factory()->create(['retention_months' => 6]);
        $longCompany = Company::factory()->create(['retention_months' => 60]);

        $shortEvent = Event::factory()->for($shortCompany)->unlimitedCapacity()->create();
        $longEvent = Event::factory()->for($longCompany)->unlimitedCapacity()->create();

        $shortOrder = $this->orderAt($shortEvent, 'x@example.com', now()->subMonths(12));
        $longOrder = $this->orderAt($longEvent, 'x@example.com', now()->subMonths(12));

        $this->runSweep();

        $this->assertSame(GdprService::ANONYMISED_EMAIL, $shortOrder->refresh()->customer_email);
        $this->assertSame('x@example.com', $longOrder->refresh()->customer_email);
    }

    public function test_sweep_is_idempotent(): void
    {
        $company = Company::factory()->create(['retention_months' => 24]);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $this->orderAt($event, 'old@example.com', now()->subMonths(30));

        $first = $this->runSweep();
        $second = $this->runSweep();

        // The first run scrubs the one customer; the second finds nothing new.
        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    public function test_sweep_records_a_system_audit_entry_without_pii(): void
    {
        $company = Company::factory()->create(['retention_months' => 24]);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $this->orderAt($event, 'old@example.com', now()->subMonths(30), name: 'Old Customer');

        $this->runSweep();

        $log = AuditLog::query()
            ->withoutGlobalScopes()
            ->where('action', AuditLog::GDPR_RETENTION_SWEEP)
            ->where('company_id', $company->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTOR_SYSTEM, $log->actor_type);
        $this->assertSame(1, $log->context['orders_affected']);
        $this->assertSame(24, $log->context['retention_months']);
        // No PII in the trail.
        $encoded = json_encode($log->context);
        $this->assertStringNotContainsString('old@example.com', $encoded);
        $this->assertStringNotContainsString('Old Customer', (string) $log->summary);
    }

    public function test_sweep_is_scoped_per_company_for_identical_emails(): void
    {
        // Two companies hold an order for the same email, both aged past the
        // window. Only the company being swept for each is touched — tenant
        // isolation under the no-tenant scheduler path.
        $companyA = Company::factory()->create(['retention_months' => 24]);
        $companyB = Company::factory()->create(['retention_months' => 24]);
        $eventA = Event::factory()->for($companyA)->unlimitedCapacity()->create();
        $eventB = Event::factory()->for($companyB)->unlimitedCapacity()->create();

        $orderA = $this->orderAt($eventA, 'shared@example.com', now()->subMonths(30), name: 'A Customer');
        $orderB = $this->orderAt($eventB, 'shared@example.com', now()->subMonths(30), name: 'B Customer');

        $this->runSweep();

        // Both are independently anonymised, each against its own company_id —
        // neither bled into the other.
        $this->assertSame(GdprService::ANONYMISED_EMAIL, $orderA->refresh()->customer_email);
        $this->assertSame(GdprService::ANONYMISED_EMAIL, $orderB->refresh()->customer_email);
        $this->assertSame($companyA->id, $orderA->company_id);
        $this->assertSame($companyB->id, $orderB->company_id);
    }

    public function test_command_runs_the_sweep_and_reports_its_summary(): void
    {
        $company = Company::factory()->create(['retention_months' => 24]);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $this->orderAt($event, 'old@example.com', now()->subMonths(30));

        $this->artisan('customers:anonymise-expired')
            ->expectsOutputToContain('1 order(s)')
            ->assertExitCode(0);
    }
}
