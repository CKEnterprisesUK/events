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
 * Locks Requirement 11.4: when a connected account's capabilities update to
 * report charges enabled, the Platform records `stripe_charges_enabled` on the
 * Company, which is what permits paid ticket sales. A capability update that
 * does not enable charges leaves paid sales blocked.
 *
 * The observable capability-update path currently available is the return-from-
 * Stripe flow in StripeConnectController, which reads the connected account's
 * capabilities through the StripePaymentService boundary and persists the
 * charges-enabled flag. Stripe is always mocked via the container-bound
 * FakeStripePaymentService; no live call and no card data are involved.
 */
class StripeCapabilityUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    public function test_capability_update_enabling_charges_permits_paid_sales(): void
    {
        // Requirement 11.4: a connected account whose capability update reports
        // charges enabled has paid ticket sales enabled for its Company.
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_CAPABLE',
            'stripe_charges_enabled' => false,
        ]);

        // The connected account's capabilities now report charges enabled.
        $this->fakeStripe()->setChargesEnabled('acct_CAPABLE', true);

        // Drive the capability-update path (return from Stripe reads capabilities).
        $this->actingAs($owner)
            ->get(route('dashboard.stripe.return'))
            ->assertRedirect(route('dashboard.stripe.status'));

        // Paid ticket sales are now permitted for the Company.
        $company = $owner->company->fresh();
        $this->assertTrue(
            $company->stripe_charges_enabled,
            'Charges-enabled capability update should permit paid ticket sales.',
        );
        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'stripe_account_id' => 'acct_CAPABLE',
            'stripe_charges_enabled' => true,
        ]);
    }

    public function test_capability_update_without_charges_leaves_paid_sales_blocked(): void
    {
        // Requirement 11.4: a capability update that does not report charges
        // enabled must not permit paid sales.
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_NOTREADY',
            'stripe_charges_enabled' => false,
        ]);

        // Fake reports charges disabled for the account by default.

        $this->actingAs($owner)
            ->get(route('dashboard.stripe.return'))
            ->assertRedirect(route('dashboard.stripe.status'));

        $company = $owner->company->fresh();
        $this->assertFalse(
            $company->stripe_charges_enabled,
            'Without a charges-enabled capability, paid ticket sales stay blocked.',
        );
    }

    public function test_capability_update_toggling_charges_off_blocks_paid_sales_again(): void
    {
        // Requirement 11.4: the flag tracks the capability update, so an account
        // that loses the charges capability has paid sales blocked again.
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'stripe_account_id' => 'acct_TOGGLE',
            'stripe_charges_enabled' => true,
        ]);

        // A later capability update reports charges are no longer enabled.
        $this->fakeStripe()->setChargesEnabled('acct_TOGGLE', false);

        $this->actingAs($owner)
            ->get(route('dashboard.stripe.return'))
            ->assertRedirect(route('dashboard.stripe.status'));

        $this->assertFalse(
            $owner->company->fresh()->stripe_charges_enabled,
            'A capability update removing charges should block paid sales again.',
        );
    }
}
