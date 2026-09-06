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

    public function test_non_owner_company_user_is_denied_and_company_unchanged(): void
    {
        // ACTION_MANAGE_STRIPE is Owner-only: an Admin is denied (403) and the
        // Company's Stripe association is left unchanged.
        $company = Company::factory()->create(['stripe_account_id' => null]);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->get(route('dashboard.stripe.status'))->assertForbidden();
        $this->actingAs($admin)->post(route('dashboard.stripe.start'))->assertForbidden();
        $this->actingAs($admin)->get(route('dashboard.stripe.return'))->assertForbidden();

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
}
