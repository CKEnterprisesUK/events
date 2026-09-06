<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 23.1.
 *
 * Covers the separate super-admin surface: the `super.admin` guard on the
 * reserved `/admin` prefix (deny non-super-admins and guests), and the three
 * SuperAdmin controllers — CompanyController (list + suspend/unsuspend),
 * FeeController (Global_Fee_Percent + per-Company override/mode), and
 * TransactionController (all Companies' transactions + total Application_Fees,
 * cross-Company, not tenant-scoped).
 *
 * Requirements:
 *   - 20.1 A super-admin dashboard separate from the Company dashboards.
 *   - 20.2 Displays all Companies, all transactions, total Application_Fees.
 *   - 20.3 Suspend a Company (marks it a Suspended_Company).
 *   - 20.4 Unsuspend a Company (removes suspended status).
 *   - 20.5 Set the Global_Fee_Percent (applied where no override).
 *   - 20.6 Set a per-Company Company_Fee_Percent override.
 *   - 20.7 Non-Super_Admins are denied access.
 */
class SuperAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * A confirmed Order in the given Company/Event with a fee snapshot,
     * created bypassing the tenant scope so we can seed cross-Company data.
     */
    private function confirmedOrder(Event $event, string $status, int $applicationFee, int $total): Order
    {
        return Order::factory()->forEvent($event)->create([
            'status' => $status,
            'ticket_subtotal_minor' => $total,
            'application_fee_minor' => $applicationFee,
            'order_total_minor' => $total,
            'fulfilled_at' => now(),
        ]);
    }

    // ---- Access control (20.1, 20.7) ---------------------------------------

    public function test_super_admin_can_reach_the_admin_dashboard(): void
    {
        // Requirement 20.1 — the separate super-admin surface is reachable.
        $this->actingAs($this->superAdmin())->get('/admin')->assertOk();
        $this->actingAs($this->superAdmin())->get('/admin/companies')->assertOk();
        $this->actingAs($this->superAdmin())->get('/admin/fees')->assertOk();
        $this->actingAs($this->superAdmin())->get('/admin/transactions')->assertOk();
    }

    public function test_company_users_are_denied_the_admin_dashboard(): void
    {
        // Requirement 20.7 — no Company role grants the super-admin surface.
        foreach ([
            User::factory()->owner()->create(),
            User::factory()->admin()->create(),
            User::factory()->accountant()->create(),
            User::factory()->scanner()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/admin')->assertForbidden();
            $this->actingAs($user)->get('/admin/companies')->assertForbidden();
            $this->actingAs($user)->get('/admin/fees')->assertForbidden();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        // Requirement 20.7 — guests never reach the super-admin surface.
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/companies')->assertRedirect('/login');
        $this->get('/admin/fees')->assertRedirect('/login');
    }

    // ---- Companies: list + suspend / unsuspend (20.1, 20.3, 20.4) ----------

    public function test_company_listing_shows_all_companies_across_the_platform(): void
    {
        // Requirement 20.1 — all Companies, not tenant-scoped.
        $a = Company::factory()->create(['name' => 'Alpha Org']);
        $b = Company::factory()->create(['name' => 'Beta Org']);

        $companies = $this->actingAs($this->superAdmin())
            ->get('/admin/companies')
            ->assertOk()
            ->viewData('companies');

        $ids = $companies->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_suspend_marks_company_as_suspended_and_takes_effect(): void
    {
        // Requirement 20.3 — persist suspended status; enforcement is live.
        $company = Company::factory()->create();
        $owner = User::factory()->owner()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$company->id}/suspend")
            ->assertRedirect(route('admin.companies.index'));

        $this->assertSame(Company::STATUS_SUSPENDED, $company->fresh()->status);

        // Suspension takes effect immediately: the storefront 404s (ResolveTenant)
        // and the Company_User's next authenticated request is denied
        // (EnsureCompanyActive).
        $this->get('/'.$company->slug)->assertNotFound();
        $this->actingAs($owner->fresh())->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_unsuspend_restores_company_access(): void
    {
        // Requirement 20.4 — remove suspended status; access restored.
        $company = Company::factory()->suspended()->create();

        $this->actingAs($this->superAdmin())
            ->post("/admin/companies/{$company->id}/unsuspend")
            ->assertRedirect(route('admin.companies.index'));

        $this->assertSame(Company::STATUS_ACTIVE, $company->fresh()->status);

        // Storefront reachable again.
        $this->get('/'.$company->slug)->assertOk();
    }

    public function test_non_super_admin_cannot_suspend_a_company(): void
    {
        // Requirement 20.7 — the suspend action itself is guarded.
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post("/admin/companies/{$company->id}/suspend")
            ->assertForbidden();

        $this->assertSame(Company::STATUS_ACTIVE, $company->fresh()->status);
    }

    // ---- Fees: global + per-company (20.5, 20.6) ---------------------------

    public function test_super_admin_sets_the_global_fee_percent(): void
    {
        // Requirement 20.5 — the Global_Fee_Percent is set and applied to
        // Companies without an override.
        PlatformSetting::current();

        $this->actingAs($this->superAdmin())
            ->put('/admin/fees/global', ['global_fee_percent' => '7.50'])
            ->assertRedirect(route('admin.fees.index'));

        $this->assertSame('7.50', PlatformSetting::current()->fresh()->global_fee_percent);

        // A Company with no override picks up the new global via the fee engine.
        $company = Company::factory()->create(['company_fee_percent' => null]);
        $fee = app(\App\Services\FeeCalculationService::class)->effectivePercent($company);
        $this->assertSame('7.50', $fee);
    }

    public function test_global_fee_percent_is_validated(): void
    {
        $this->actingAs($this->superAdmin())
            ->from('/admin/fees')
            ->put('/admin/fees/global', ['global_fee_percent' => '150'])
            ->assertRedirect('/admin/fees')
            ->assertSessionHasErrors('global_fee_percent');
    }

    public function test_super_admin_sets_a_per_company_fee_override_and_mode(): void
    {
        // Requirement 20.6 — a per-Company override is applied to that Company.
        $company = Company::factory()->create([
            'company_fee_percent' => null,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
        ]);

        $this->actingAs($this->superAdmin())
            ->put("/admin/fees/companies/{$company->id}", [
                'company_fee_percent' => '3.25',
                'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
            ])
            ->assertRedirect(route('admin.fees.index'));

        $fresh = $company->fresh();
        $this->assertSame('3.25', $fresh->company_fee_percent);
        $this->assertSame(Company::FEE_MODE_PASS_ON, $fresh->fee_handling_mode);

        // The override wins over the global fee for this Company's transactions.
        PlatformSetting::current()->update(['global_fee_percent' => '9.99']);
        $this->assertSame('3.25', app(\App\Services\FeeCalculationService::class)->effectivePercent($fresh));
    }

    public function test_clearing_the_per_company_override_falls_back_to_global(): void
    {
        // Requirement 20.6 — a blank override returns the Company to the global.
        $company = Company::factory()->create(['company_fee_percent' => '4.00']);

        $this->actingAs($this->superAdmin())
            ->put("/admin/fees/companies/{$company->id}", [
                'company_fee_percent' => '',
                'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            ])
            ->assertRedirect(route('admin.fees.index'));

        $this->assertNull($company->fresh()->company_fee_percent);
    }

    // ---- Transactions: cross-company + total fees (20.2) -------------------

    public function test_transactions_view_sums_application_fees_across_all_companies(): void
    {
        // Requirement 20.2 — total Application_Fees is the sum of the recorded
        // fee on paid/confirmed Orders across ALL Companies, not tenant-scoped.
        $companyA = Company::factory()->create();
        $eventA = Event::factory()->for($companyA)->unlimitedCapacity()->create();

        $companyB = Company::factory()->create();
        $eventB = Event::factory()->for($companyB)->unlimitedCapacity()->create();

        $this->confirmedOrder($eventA, Order::STATUS_PAID, 500, 10_000);
        $this->confirmedOrder($eventA, Order::STATUS_FREE_CONFIRMED, 0, 0);
        $this->confirmedOrder($eventB, Order::STATUS_PAID, 250, 5_000);

        // Non-fee-earning Orders must not contribute.
        $this->confirmedOrder($eventB, Order::STATUS_REFUNDED, 9_999, 9_999);
        $this->confirmedOrder($eventA, Order::STATUS_RESERVED, 8_888, 8_888);

        $response = $this->actingAs($this->superAdmin())->get('/admin/transactions')->assertOk();

        // 500 + 0 + 250 across both companies = 750.
        $this->assertSame(750, $response->viewData('totalApplicationFeesMinor'));
    }

    public function test_transactions_view_lists_orders_from_every_company(): void
    {
        // Requirement 20.2 — cross-Company visibility (bypasses the tenant scope).
        $companyA = Company::factory()->create(['name' => 'Company A']);
        $eventA = Event::factory()->for($companyA)->unlimitedCapacity()->create();
        $orderA = $this->confirmedOrder($eventA, Order::STATUS_PAID, 100, 2_000);

        $companyB = Company::factory()->create(['name' => 'Company B']);
        $eventB = Event::factory()->for($companyB)->unlimitedCapacity()->create();
        $orderB = $this->confirmedOrder($eventB, Order::STATUS_PAID, 200, 4_000);

        $transactions = $this->actingAs($this->superAdmin())
            ->get('/admin/transactions')
            ->assertOk()
            ->viewData('transactions');

        $orderIds = collect($transactions)->pluck('id')->all();
        $this->assertContains($orderA->id, $orderIds);
        $this->assertContains($orderB->id, $orderIds);

        // Each transaction is attributed to its owning Company.
        $byOrder = collect($transactions)->keyBy('id');
        $this->assertSame('Company A', $byOrder[$orderA->id]['company_name']);
        $this->assertSame('Company B', $byOrder[$orderB->id]['company_name']);
    }
}
