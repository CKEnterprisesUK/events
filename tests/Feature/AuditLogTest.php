<?php

namespace Tests\Feature;

use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Jobs\PruneAuditLogsJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: audit trail.
 *
 * Covers the AuditLogger's actor/tenant/impersonation resolution, the sensitive
 * write-site hooks, the organiser + super-admin views (auth + scoping), the
 * GDPR PII-minimisation guarantee, and the retention prune job.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    // ---- Logger: actor / tenant / impersonation resolution ------------------

    public function test_record_captures_the_authenticated_actor_and_company(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        $this->actingAs($admin);

        app(AuditLogger::class)->record(
            action: AuditLog::EVENT_UPDATED,
            summary: 'Did a thing',
        );

        $log = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame($company->id, $log->company_id);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertFalse($log->is_impersonated);
        $this->assertStringContainsString($admin->email, (string) $log->actor_label);
    }

    public function test_record_flags_impersonation_for_a_jumped_in_super_admin(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        // Simulate the jumped-in state: authenticated super admin + session flag.
        $this->actingAs($superAdmin)
            ->withSession([ImpersonationController::SESSION_KEY => $company->id]);

        app(AuditLogger::class)->record(
            action: AuditLog::ORDER_REFUNDED,
            summary: 'Refunded something',
            companyId: $company->id,
        );

        $log = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertTrue($log->is_impersonated);
        $this->assertSame($superAdmin->id, $log->impersonator_user_id);
        $this->assertSame(AuditLog::ACTOR_SUPER_ADMIN, $log->actor_type);
        $this->assertSame($company->id, $log->company_id);
    }

    public function test_record_system_has_no_actor(): void
    {
        $company = Company::factory()->create();

        app(AuditLogger::class)->recordSystem(
            action: AuditLog::WEBHOOK_PAYMENT_CONFIRMED,
            summary: 'Payment confirmed',
            companyId: $company->id,
        );

        $log = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertNull($log->actor_user_id);
        $this->assertSame(AuditLog::ACTOR_SYSTEM, $log->actor_type);
        $this->assertSame('System', $log->actor_label);
    }

    // ---- Write-site hooks ----------------------------------------------------

    public function test_impersonation_start_and_stop_are_recorded(): void
    {
        $company = Company::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->post(route('admin.impersonate.start', $company))
            ->assertRedirect(route('dashboard.home'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::IMPERSONATION_STARTED,
            'company_id' => $company->id,
            'impersonator_user_id' => $superAdmin->id,
            'is_impersonated' => true,
        ]);

        $this->actingAs($superAdmin)
            ->withSession([ImpersonationController::SESSION_KEY => $company->id])
            ->post(route('admin.impersonate.stop'))
            ->assertRedirect(route('admin.home'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::IMPERSONATION_STOPPED,
            'company_id' => $company->id,
        ]);
    }

    public function test_refund_records_an_audit_row_only_once(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();
        [$order] = $this->confirmedOrder($company);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        // A second refund is an idempotent no-op and must not log again.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/refund");

        $this->assertSame(1, AuditLog::where('action', AuditLog::ORDER_REFUNDED)
            ->where('auditable_id', $order->id)->count());
    }

    public function test_role_change_is_recorded(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->owner()->for($company)->create();
        $member = User::factory()->scanner()->for($company)->create();

        $this->actingAs($owner)
            ->put("/dashboard/users/{$member->id}/role", ['role' => User::ROLE_ADMIN])
            ->assertRedirect(route('dashboard.users.index'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::USER_ROLE_CHANGED,
            'company_id' => $company->id,
            'actor_user_id' => $owner->id,
        ]);
    }

    public function test_gdpr_export_records_without_storing_customer_pii(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->owner()->for($company)->create();
        [$order] = $this->confirmedOrder($company);

        $email = $order->customer_email;
        $token = \App\Http\Controllers\CustomerController::tokenFor($email);

        $this->actingAs($owner)
            ->post("/dashboard/customers/{$token}/export")
            ->assertOk();

        $log = AuditLog::where('action', AuditLog::GDPR_CUSTOMER_EXPORTED)->firstOrFail();

        // The email must never appear in the summary or context payload.
        $serialised = json_encode([$log->summary, $log->context]);
        $this->assertStringNotContainsString($email, (string) $serialised);
        $this->assertArrayHasKey('customer_ref', (array) $log->context);
    }

    // ---- Views: auth + scoping ----------------------------------------------

    public function test_activity_view_is_denied_to_operational_roles(): void
    {
        $scanner = User::factory()->scanner()->create();

        $this->actingAs($scanner)->get('/dashboard/activity')->assertForbidden();
    }

    public function test_activity_view_only_shows_the_acting_company_rows(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        AuditLog::factory()->for($company)->action(AuditLog::EVENT_CREATED)
            ->create(['summary' => 'MINE created an event']);
        AuditLog::factory()->for($other)->action(AuditLog::EVENT_CREATED)
            ->create(['summary' => 'THEIRS created an event']);

        $response = $this->actingAs($admin)->get('/dashboard/activity');

        $response->assertOk();
        $response->assertSee('MINE created an event');
        $response->assertDontSee('THEIRS created an event');
    }

    public function test_super_admin_audit_view_is_cross_tenant_and_can_filter_impersonated(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        AuditLog::factory()->for($companyA)->create(['summary' => 'plain A action']);
        AuditLog::factory()->for($companyB)->impersonated($superAdmin)
            ->create(['summary' => 'impersonated B action']);

        // Cross-tenant: both companies visible.
        $this->actingAs($superAdmin)->get('/admin/audit')
            ->assertOk()
            ->assertSee('plain A action')
            ->assertSee('impersonated B action');

        // Impersonated-only toggle hides the plain row.
        $this->actingAs($superAdmin)->get('/admin/audit?impersonated=1')
            ->assertOk()
            ->assertSee('impersonated B action')
            ->assertDontSee('plain A action');
    }

    public function test_company_user_cannot_reach_super_admin_audit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/audit')->assertForbidden();
    }

    // ---- Retention prune -----------------------------------------------------

    public function test_prune_deletes_rows_past_retention_and_keeps_recent(): void
    {
        $company = Company::factory()->create();

        $old = AuditLog::factory()->for($company)->create([
            'created_at' => now()->subMonths(PruneAuditLogsJob::RETENTION_MONTHS + 1),
        ]);
        $recent = AuditLog::factory()->for($company)->create([
            'created_at' => now()->subMonths(1),
        ]);

        $deleted = (new PruneAuditLogsJob)->handle();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }

    // ---- Helpers -------------------------------------------------------------

    /**
     * A confirmed, paid Order for the Company with one ticket, ready to refund.
     *
     * @return array{0: Order, 1: TicketType, 2: Event}
     */
    private function confirmedOrder(Company $company): array
    {
        $event = Event::factory()->for($company)->create();
        $type = TicketType::factory()->for($company)->for($event)->create([
            'price_minor' => 2500,
        ]);

        $order = Order::factory()->for($company)->for($event)->create([
            'status' => Order::STATUS_PAID,
            'order_total_minor' => 2500,
            'stripe_charge_id' => 'ch_test_'.uniqid(),
        ]);

        return [$order, $type, $event];
    }
}
