<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripeDiagnosticResult;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Feature: super-admin platform operations added on top of the base admin
 * surface — Stripe credential validation, connected-account visibility, system
 * health, and owner/user recovery. All exercised through the reserved `/admin`
 * prefix behind the `super.admin` guard; Stripe is always the fake so no live
 * call is ever made.
 */
class SuperAdminPlatformOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ---- Stripe credential validation --------------------------------------

    public function test_settings_page_shows_stripe_credential_summary(): void
    {
        Config::set('stripe.secret', 'sk_test_abc1234567890');
        Config::set('stripe.webhook_secret', 'whsec_xyz');

        $this->actingAs($this->superAdmin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('Stripe credentials')
            ->assertSee('TEST');
    }

    public function test_stripe_diagnostics_reports_valid_credentials(): void
    {
        Config::set('stripe.secret', 'sk_test_valid');

        $this->actingAs($this->superAdmin())
            ->post('/admin/settings/stripe-diagnostics')
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::STRIPE_CREDENTIALS_CHECKED,
        ]);
    }

    public function test_stripe_diagnostics_reports_invalid_credentials(): void
    {
        Config::set('stripe.secret', 'sk_test_bad');

        /** @var FakeStripePaymentService $fake */
        $fake = $this->app->make(StripePaymentService::class);
        $fake->setPlatformCredentialsResult(StripeDiagnosticResult::failure(
            stage: 'auth',
            message: 'Stripe rejected the API secret key.',
            hint: 'Check STRIPE_SECRET.',
        ));

        $this->actingAs($this->superAdmin())
            ->post('/admin/settings/stripe-diagnostics')
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('error');
    }

    public function test_stripe_diagnostics_reports_missing_configuration(): void
    {
        Config::set('stripe.secret', '');

        $this->actingAs($this->superAdmin())
            ->post('/admin/settings/stripe-diagnostics')
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('error');
    }

    // ---- Connected-account visibility --------------------------------------

    public function test_connected_accounts_page_groups_companies_by_stripe_state(): void
    {
        $ready = Company::factory()->create([
            'name' => 'Ready Co',
            'stripe_account_id' => 'acct_ready',
            'stripe_charges_enabled' => true,
        ]);
        $incomplete = Company::factory()->create([
            'name' => 'Incomplete Co',
            'stripe_account_id' => 'acct_incomplete',
            'stripe_charges_enabled' => false,
        ]);
        $none = Company::factory()->create([
            'name' => 'Not Started Co',
            'stripe_account_id' => null,
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee('Ready Co')
            ->assertSee('Incomplete Co')
            ->assertSee('Not Started Co')
            ->assertSee('acct_incomplete');

        // The three groups are reflected in the totals.
        $response->assertViewHas('totals', function (array $totals): bool {
            return $totals['ready'] === 1
                && $totals['incomplete'] === 1
                && $totals['none'] === 1;
        });
    }

    // ---- System health ------------------------------------------------------

    public function test_system_health_page_reports_healthy_on_a_clean_test_environment(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/system')
            ->assertOk()
            ->assertSee('System health')
            ->assertViewHas('report', function (array $report): bool {
                return $report['healthy'] === true
                    && collect($report['checks'])->pluck('key')->contains('database')
                    && collect($report['checks'])->pluck('key')->contains('queue')
                    && collect($report['checks'])->pluck('key')->contains('failed_jobs')
                    && collect($report['checks'])->pluck('key')->contains('cache');
            });
    }

    // ---- Owner / user recovery ---------------------------------------------

    public function test_super_admin_can_send_the_owner_a_password_reset(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $owner = User::factory()->owner()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.companies.owner.password-reset', $company))
            ->assertRedirect(route('admin.clients.show', $company))
            ->assertSessionHas('status');

        Notification::assertSentTo($owner, ResetPassword::class);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::OWNER_PASSWORD_RESET_SENT,
            'company_id' => $company->id,
        ]);
    }

    public function test_super_admin_can_resend_verification_to_an_unverified_owner(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $owner = User::factory()->owner()->unverified()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.companies.owner.resend-verification', $company))
            ->assertRedirect(route('admin.clients.show', $company))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::USER_VERIFICATION_RESENT,
            'company_id' => $company->id,
        ]);
    }

    public function test_super_admin_can_transfer_ownership_to_another_user(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->owner()->create(['company_id' => $company->id]);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.companies.owner.transfer', $company), [
                'user_id' => $admin->id,
            ])
            ->assertRedirect(route('admin.clients.show', $company))
            ->assertSessionHas('status');

        // The role moved: the target is now the sole Owner, the previous owner demoted.
        $this->assertSame(User::ROLE_OWNER, $admin->fresh()->role);
        $this->assertSame(User::ROLE_ADMIN, $owner->fresh()->role);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::OWNER_TRANSFERRED,
            'company_id' => $company->id,
        ]);
    }

    public function test_ownership_transfer_rejects_a_user_from_another_company(): void
    {
        $company = Company::factory()->create();
        User::factory()->owner()->create(['company_id' => $company->id]);

        $otherCompany = Company::factory()->create();
        $outsider = User::factory()->admin()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.companies.owner.transfer', $company), [
                'user_id' => $outsider->id,
            ])
            ->assertSessionHasErrors('user_id');

        // Nothing changed: the outsider is still a plain admin of the other company.
        $this->assertSame(User::ROLE_ADMIN, $outsider->fresh()->role);
    }

    public function test_owner_recovery_actions_are_denied_to_non_super_admins(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->owner()->create(['company_id' => $company->id]);

        $this->actingAs($owner)
            ->post(route('admin.companies.owner.password-reset', $company))
            ->assertForbidden();
    }
}
