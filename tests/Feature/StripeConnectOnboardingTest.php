<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers Owner-initiated Stripe Connect Standard onboarding through
 * StripeConnectController and the StripePaymentService boundary. Stripe is
 * always mocked via the container-bound FakeStripePaymentService; no live call
 * and no card data are involved. (Requirements 11.1, 11.2, 11.3, 11.5)
 */
class StripeConnectOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    public function test_testing_environment_binds_the_fake_stripe_boundary(): void
    {
        $this->assertInstanceOf(
            FakeStripePaymentService::class,
            app(StripePaymentService::class),
        );
    }

    public function test_status_shows_not_connected_when_no_account_stored(): void
    {
        // Requirement 11.5: no connected account → status is "not connected".
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get(route('dashboard.stripe.status'));

        $response->assertOk();
        $response->assertSee('Not connected');
        $response->assertSee('not_connected');
    }

    public function test_owner_starting_onboarding_creates_and_stores_account_and_redirects(): void
    {
        // Requirements 11.1, 11.2: onboarding directs the Owner to Stripe and
        // the connected account association is stored on the Company.
        $this->fakeStripe()->nextAccountId('acct_TESTOWNER1');

        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->post(route('dashboard.stripe.start'));

        $response->assertRedirect('https://connect.stripe.test/onboarding/acct_TESTOWNER1');

        $this->assertDatabaseHas('companies', [
            'id' => $owner->company_id,
            'stripe_account_id' => 'acct_TESTOWNER1',
        ]);

        // The onboarding link was requested with our return/refresh URLs.
        $call = $this->fakeStripe()->onboardingLinkCalls[0];
        $this->assertSame(route('dashboard.stripe.return'), $call['return_url']);
        $this->assertSame(route('dashboard.stripe.start'), $call['refresh_url']);
    }

    public function test_starting_onboarding_reuses_existing_account_id(): void
    {
        // Requirement 11.2: an in-progress onboarding resumes the same account.
        $owner = User::factory()->owner()->create();
        $owner->company->update(['stripe_account_id' => 'acct_EXISTING']);

        $response = $this->actingAs($owner)->post(route('dashboard.stripe.start'));

        $response->assertRedirect('https://connect.stripe.test/onboarding/acct_EXISTING');
        $this->assertSame('acct_EXISTING', $this->fakeStripe()->onboardingLinkCalls[0]['account_id']);
    }

    public function test_return_reads_capabilities_and_persists_charges_enabled(): void
    {
        // Requirements 11.2, 11.3: on return, capabilities are read and the
        // charges-enabled flag is persisted for the Company.
        $owner = User::factory()->owner()->create();
        $owner->company->update(['stripe_account_id' => 'acct_CONNECTED']);
        $this->fakeStripe()->setChargesEnabled('acct_CONNECTED', true);

        $response = $this->actingAs($owner)->get(route('dashboard.stripe.return'));

        $response->assertRedirect(route('dashboard.stripe.status'));
        $this->assertDatabaseHas('companies', [
            'id' => $owner->company_id,
            'stripe_account_id' => 'acct_CONNECTED',
            'stripe_charges_enabled' => true,
        ]);
    }

    public function test_return_leaves_charges_disabled_when_capabilities_not_enabled(): void
    {
        // Requirement 11.3: a connected account without charges enabled must
        // not be marked charges-enabled.
        $owner = User::factory()->owner()->create();
        $owner->company->update(['stripe_account_id' => 'acct_PENDING']);
        // Fake defaults to charges disabled for the account.

        $this->actingAs($owner)->get(route('dashboard.stripe.return'))->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $owner->company_id,
            'stripe_charges_enabled' => false,
        ]);
    }

    public function test_status_shows_charges_enabled_once_connected(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_LIVE',
            'stripe_charges_enabled' => true,
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.stripe.status'));

        $response->assertOk();
        $response->assertSee('charges_enabled');
    }

    public function test_admin_can_set_up_stripe_but_not_see_fee_controls(): void
    {
        // ACTION_SETUP_STRIPE is held by the Admin: an Admin can view the status
        // page and drive onboarding, so payments can be connected without the
        // Owner. But the Owner-only fee-handling controls are hidden from them.
        $this->fakeStripe()->nextAccountId('acct_ADMINSETUP');
        $company = Company::factory()->create(['stripe_account_id' => null]);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $status = $this->actingAs($admin)->get(route('dashboard.stripe.status'));
        $status->assertOk();
        $status->assertSee('Not connected');
        // The fee-handling form is Owner-only; the Admin sees the read-only note.
        $status->assertDontSee('Save fee handling');
        $status->assertSee('Only the account');

        $this->actingAs($admin)
            ->post(route('dashboard.stripe.start'))
            ->assertRedirect('https://connect.stripe.test/onboarding/acct_ADMINSETUP');

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'stripe_account_id' => 'acct_ADMINSETUP',
        ]);
    }

    public function test_role_without_stripe_setup_is_denied_and_company_unchanged(): void
    {
        // A Box_Office user holds neither ACTION_SETUP_STRIPE nor
        // ACTION_MANAGE_STRIPE: denied (403) and the Company is left unchanged.
        $company = Company::factory()->create(['stripe_account_id' => null]);
        $boxOffice = User::factory()->boxOffice()->create(['company_id' => $company->id]);

        $this->actingAs($boxOffice)->get(route('dashboard.stripe.status'))->assertForbidden();
        $this->actingAs($boxOffice)->post(route('dashboard.stripe.start'))->assertForbidden();
        $this->actingAs($boxOffice)->get(route('dashboard.stripe.return'))->assertForbidden();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'stripe_account_id' => null,
        ]);
        $this->assertSame([], $this->fakeStripe()->onboardingLinkCalls);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.stripe.status'))->assertRedirect(route('login'));
    }

    public function test_owner_can_switch_the_fee_handling_mode_from_payments(): void
    {
        // Default is pass_on; the Owner switches to absorb from the Payments page.
        $owner = User::factory()->owner()->create();
        $this->assertSame(Company::FEE_MODE_PASS_ON, $owner->company->fee_handling_mode);

        $this->actingAs($owner)->put(route('dashboard.stripe.fee-mode'), [
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
        ])->assertRedirect(route('dashboard.stripe.status'));

        $this->assertSame(Company::FEE_MODE_ABSORB, $owner->company->fresh()->fee_handling_mode);
    }

    public function test_fee_mode_rejects_an_unsupported_value(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.stripe.fee-mode'), [
            'fee_handling_mode' => 'waive',
        ])->assertSessionHasErrors('fee_handling_mode');

        // Unchanged from the default.
        $this->assertSame(Company::FEE_MODE_PASS_ON, $owner->company->fresh()->fee_handling_mode);
    }

    public function test_non_owner_cannot_change_the_fee_mode(): void
    {
        $company = Company::factory()->create(['fee_handling_mode' => Company::FEE_MODE_PASS_ON]);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->put(route('dashboard.stripe.fee-mode'), [
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
        ])->assertForbidden();

        $this->assertSame(Company::FEE_MODE_PASS_ON, $company->fresh()->fee_handling_mode);
    }
}
