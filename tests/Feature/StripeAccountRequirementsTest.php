<?php

namespace Tests\Feature;

use App\Jobs\RefreshStripeAccountStateJob;
use App\Models\Company;
use App\Models\User;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers surfacing a connected account's compliance/verification state to the
 * Company (Requirements 11.3, 11.4): returning from Stripe onboarding does NOT
 * mean onboarding finished, so the Platform reads and persists the account's
 * requirements, disabled reason, payouts-enabled and details-submitted flags,
 * and the Payments page shows the Company exactly what is still outstanding
 * (e.g. a business verification document) with a "Finish Stripe setup" button.
 * The `account.updated` webhook keeps that state in sync automatically. Stripe
 * is always mocked via the container-bound FakeStripePaymentService.
 */
class StripeAccountRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    public function test_return_persists_outstanding_requirements_and_disabled_reason(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_RESTRICTED',
            'stripe_charges_enabled' => false,
        ]);

        // Stripe reports the account restricted: charges off, a business
        // verification document past due, and a human-facing error.
        $this->fakeStripe()->setChargesEnabled('acct_RESTRICTED', false);
        $this->fakeStripe()->setAccountState(
            'acct_RESTRICTED',
            payoutsEnabled: false,
            detailsSubmitted: true,
            disabledReason: 'requirements.past_due',
            pastDue: ['company.verification.document'],
            errors: [[
                'requirement' => 'company.verification.document',
                'code' => 'verification_document_missing',
                'reason' => 'A business verification document is required.',
            ]],
        );

        $this->actingAs($owner)
            ->get(route('dashboard.stripe.return'))
            ->assertRedirect(route('dashboard.stripe.status'));

        $company = $owner->company->fresh();
        $this->assertFalse($company->stripe_charges_enabled);
        $this->assertFalse($company->stripe_payouts_enabled);
        $this->assertTrue($company->stripe_details_submitted);
        $this->assertSame('requirements.past_due', $company->stripe_disabled_reason);
        $this->assertSame(
            ['company.verification.document'],
            $company->stripe_requirements['past_due'],
        );
        $this->assertSame(
            'A business verification document is required.',
            $company->stripe_requirements['errors'][0]['reason'],
        );
    }

    public function test_status_page_lists_requirements_and_finish_setup_button(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_RESTRICTED',
            'stripe_charges_enabled' => false,
            'stripe_disabled_reason' => 'requirements.past_due',
            'stripe_requirements' => [
                'currently_due' => [],
                'past_due' => ['company.verification.document'],
                'pending_verification' => [],
                'errors' => [[
                    'requirement' => 'company.verification.document',
                    'code' => 'verification_document_missing',
                    'reason' => 'A business verification document is required.',
                ]],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.stripe.status'));

        $response->assertOk();
        $response->assertSee('Business verification document');
        $response->assertSee('A business verification document is required.');
        $response->assertSee('Finish Stripe setup');
        $response->assertSee('data-req="action-required"', false);
    }

    public function test_status_page_shows_pending_review_when_only_pending(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_PENDING',
            'stripe_charges_enabled' => false,
            'stripe_details_submitted' => true,
            'stripe_requirements' => [
                'currently_due' => [],
                'past_due' => [],
                'pending_verification' => ['company.verification.document'],
                'errors' => [],
            ],
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.stripe.status'));

        $response->assertOk();
        $response->assertSee('verification in progress');
        $response->assertSee('data-req="pending-verification"', false);
    }

    public function test_account_updated_webhook_syncs_requirements(): void
    {
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_SYNC',
            'stripe_charges_enabled' => false,
        ]);

        $event = new StripeWebhookEvent(
            id: 'evt_acct_req_1',
            type: 'account.updated',
            data: [
                'id' => 'acct_SYNC',
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'details_submitted' => true,
                'requirements' => [
                    'disabled_reason' => 'requirements.pending_verification',
                    'currently_due' => [],
                    'past_due' => [],
                    'pending_verification' => ['company.verification.document'],
                    'errors' => [],
                ],
            ],
        );

        app(WebhookProcessor::class)->process($event);

        $company->refresh();
        $this->assertFalse($company->stripe_charges_enabled);
        $this->assertTrue($company->stripe_details_submitted);
        $this->assertSame('requirements.pending_verification', $company->stripe_disabled_reason);
        $this->assertSame(
            ['company.verification.document'],
            $company->stripe_requirements['pending_verification'],
        );
    }

    public function test_admin_connected_accounts_page_shows_outstanding_requirements(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        Company::factory()->create([
            'name' => 'Stuck Co',
            'stripe_account_id' => 'acct_stuck',
            'stripe_charges_enabled' => false,
            'stripe_disabled_reason' => 'requirements.past_due',
            'stripe_requirements' => [
                'currently_due' => [],
                'past_due' => ['company.verification.document'],
                'pending_verification' => [],
                'errors' => [],
            ],
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('Stuck Co')
            ->assertSee('Business verification document')
            ->assertSee('Action needed from client:');
    }

    public function test_admin_client_detail_shows_stripe_verification_breakdown(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $company = Company::factory()->create([
            'name' => 'Detail Co',
            'stripe_account_id' => 'acct_detail',
            'stripe_charges_enabled' => false,
            'stripe_payouts_enabled' => false,
            'stripe_details_submitted' => true,
            'stripe_disabled_reason' => 'requirements.past_due',
            'stripe_requirements' => [
                'currently_due' => [],
                'past_due' => ['company.verification.document'],
                'pending_verification' => [],
                'errors' => [[
                    'requirement' => 'company.verification.document',
                    'code' => 'verification_document_missing',
                    'reason' => 'A business verification document is required.',
                ]],
            ],
        ]);

        $this->actingAs($superAdmin)
            ->get(route('admin.clients.show', $company))
            ->assertOk()
            ->assertSee('Business verification document')
            ->assertSee('A business verification document is required.')
            ->assertSee('requirements.past_due')
            ->assertSee('Waiting on the client');
    }

    public function test_refresh_job_backfills_requirements_for_existing_connected_account(): void
    {
        // An existing customer connected before this feature existed: they have
        // a connected account but the requirements columns were never populated
        // (no onboarding return, no account.updated webhook since). The sweeper
        // re-reads Stripe and fills them in so the customer sees what's blocked.
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_EXISTING',
            'stripe_charges_enabled' => false,
            'stripe_requirements' => null,
            'stripe_disabled_reason' => null,
        ]);

        $this->fakeStripe()->setChargesEnabled('acct_EXISTING', false);
        $this->fakeStripe()->setAccountState(
            'acct_EXISTING',
            payoutsEnabled: false,
            detailsSubmitted: true,
            disabledReason: 'requirements.past_due',
            pastDue: ['company.verification.document'],
            errors: [[
                'requirement' => 'company.verification.document',
                'code' => 'verification_document_missing',
                'reason' => 'A business verification document is required.',
            ]],
        );

        $refreshed = app(RefreshStripeAccountStateJob::class)->handle($this->fakeStripe());

        $this->assertSame(1, $refreshed);

        $company->refresh();
        $this->assertSame('requirements.past_due', $company->stripe_disabled_reason);
        $this->assertSame(
            ['company.verification.document'],
            $company->stripe_requirements['past_due'],
        );
        $this->assertFalse($company->stripe_payouts_enabled);
        $this->assertTrue($company->stripe_details_submitted);
    }

    public function test_refresh_job_skips_companies_with_no_connected_account(): void
    {
        $company = Company::factory()->create([
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
        ]);

        $refreshed = app(RefreshStripeAccountStateJob::class)->handle($this->fakeStripe());

        $this->assertSame(0, $refreshed);
        $this->assertNull($company->fresh()->stripe_requirements);
    }

    public function test_refresh_command_is_registered_and_runs(): void
    {
        Company::factory()->create([
            'stripe_account_id' => 'acct_CMD',
            'stripe_charges_enabled' => true,
        ]);
        $this->fakeStripe()->setChargesEnabled('acct_CMD', true);

        $this->artisan('stripe:refresh-accounts')
            ->assertExitCode(0);
    }

    public function test_super_admin_can_refresh_stripe_accounts_from_ops_page(): void
    {
        // The no-SSH / in-browser equivalent of the cron command: a Super_Admin
        // triggers the backfill from the build/ops page and it refreshes the
        // requirements state of an existing blocked account. (Deployment: no
        // terminal, proc_open disabled — see DEPLOYMENT.md)
        $superAdmin = User::factory()->superAdmin()->create();

        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_OPS',
            'stripe_charges_enabled' => false,
            'stripe_requirements' => null,
        ]);

        $this->fakeStripe()->setChargesEnabled('acct_OPS', false);
        $this->fakeStripe()->setAccountState(
            'acct_OPS',
            payoutsEnabled: false,
            detailsSubmitted: true,
            disabledReason: 'requirements.past_due',
            pastDue: ['company.verification.document'],
        );

        $this->actingAs($superAdmin)
            ->post(route('admin.ops.refresh-stripe-accounts'))
            ->assertRedirect()
            ->assertSessionHas('ops_status');

        $company->refresh();
        $this->assertSame('requirements.past_due', $company->stripe_disabled_reason);
        $this->assertSame(
            ['company.verification.document'],
            $company->stripe_requirements['past_due'],
        );
    }

    public function test_account_updated_webhook_clears_requirements_when_verified(): void
    {
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_VERIFIED',
            'stripe_charges_enabled' => false,
            'stripe_disabled_reason' => 'requirements.past_due',
            'stripe_requirements' => [
                'currently_due' => [],
                'past_due' => ['company.verification.document'],
                'pending_verification' => [],
                'errors' => [],
            ],
        ]);

        $event = new StripeWebhookEvent(
            id: 'evt_acct_req_2',
            type: 'account.updated',
            data: [
                'id' => 'acct_VERIFIED',
                'charges_enabled' => true,
                'payouts_enabled' => true,
                'details_submitted' => true,
                'requirements' => [
                    'disabled_reason' => null,
                    'currently_due' => [],
                    'past_due' => [],
                    'pending_verification' => [],
                    'errors' => [],
                ],
            ],
        );

        app(WebhookProcessor::class)->process($event);

        $company->refresh();
        $this->assertTrue($company->stripe_charges_enabled);
        $this->assertTrue($company->stripe_payouts_enabled);
        $this->assertNull($company->stripe_disabled_reason);
        $this->assertSame([], $company->stripe_requirements['past_due']);
    }
}
