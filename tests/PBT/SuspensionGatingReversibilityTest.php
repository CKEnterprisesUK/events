<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * Property-based test for Company suspension gating and reversibility
 * (Property 4).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * Suspension enforcement lives in two collaborating places, both exercised
 * here end-to-end:
 *   - `ResolveTenant` returns 404 for a suspended Company's storefront and
 *     event pages. (Requirements 2.1, 2.2)
 *   - `EnsureCompanyActive` + `LoginController` block login/session for a
 *     suspended Company's users. (Requirements 2.3, 2.4)
 *
 * The property has two halves:
 *   - Gating (iff): across a randomly generated sequence of suspend/unsuspend
 *     transitions, storefront + event pages + login are available at each step
 *     if and only if the Company is NOT suspended at that step. (2.1–2.4)
 *   - Reversibility: after any suspend→unsuspend cycle the Company's observable
 *     state (storefront/event/login access) is equivalent to that of a Company
 *     that was never suspended. (2.5)
 *
 * Because the events surface is routed in a later task (6.x), an event-shaped
 * route is registered here inside the real `tenant` middleware group so
 * `ResolveTenant`'s suspension gating for event paths is exercised exactly as
 * in production. Company_Users need a `company_id`; task 4.1's `users` schema
 * provides it permanently.
 */
class SuspensionGatingReversibilityTest extends PbtTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-password';

    protected function setUp(): void
    {
        parent::setUp();

        // Company_Users carry a `company_id` permanently from task 4.1's
        // `users` schema, so no column setup is needed here.

        // An event-shaped path inside the real `tenant` group so ResolveTenant
        // (and its suspended -> 404 gate) runs for event pages just as it does
        // for the storefront. Stands in for the event route added in task 6.x.
        Route::middleware('tenant')->group(function () {
            Route::get('/{companySlug}/e/{eventId}', function (TenantContext $ctx) {
                return response()->json(['company_id' => $ctx->companyId()]);
            })->where('companySlug', '[A-Za-z0-9-]+')->where('eventId', '[0-9]+');
        });
    }

    /**
     * Assert every suspension-gated surface behaves consistently with the given
     * expected-available state for the Company: storefront page, event page,
     * and Company_User login are all available iff $available is true.
     *
     * The short-lived slug->Company resolution cache in ResolveTenant is
     * flushed first so a status change made in this step is observed
     * immediately rather than served stale.
     */
    private function assertSurfaces(Company $company, User $user, bool $available): void
    {
        // Start each check from a clean slate: no lingering auth session from a
        // prior surface check, and no stale slug->Company resolution cache so a
        // status change made in this step is observed immediately. (2.5)
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        cache()->flush();

        // The storefront now renders an HTML page (not the earlier JSON stub),
        // so gating is observed via the HTTP status: an active Company's
        // storefront returns 200, a suspended one 404. The event-shaped route
        // below is a local JSON stand-in inside the real `tenant` group so
        // ResolveTenant's suspended->404 gate is still exercised for event
        // paths. (Requirements 2.1, 2.2, 2.5)
        $storefront = $this->get("/{$company->slug}");
        $event = $this->getJson("/{$company->slug}/e/1");

        if ($available) {
            $storefront->assertOk();
            $event->assertOk()->assertJsonPath('company_id', $company->id);
        } else {
            $storefront->assertNotFound();
            $event->assertNotFound();
        }

        // Login is a stateful POST; isolate its session from the reads above.
        $login = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        if ($available) {
            $this->assertAuthenticatedAs($user->fresh());
            $login->assertRedirect('/dashboard');
        } else {
            $this->assertGuest();
            $login->assertRedirect('/login');
            $login->assertSessionHasErrors('email');
        }

        // Never leak the authenticated session into the next transition.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    /**
     * A generator of non-empty suspend/unsuspend transition sequences.
     *
     * `true` = suspend, `false` = unsuspend. A guaranteed leading boolean makes
     * the sequence non-empty; the trailing (possibly empty) sequence supplies
     * the random length and interleaving of toggles.
     */
    private function transitionSequenceGenerator(): Generator
    {
        return Generator\map(
            fn (array $parts): array => array_merge([$parts[0]], $parts[1]),
            Generator\tuple(
                Generator\bool(),
                Generator\seq(Generator\bool())
            )
        );
    }

    /**
     * Property 4: Suspension gating and reversibility — across any sequence of
     * suspend/unsuspend transitions the storefront, event page, and login are
     * available if and only if the Company is not currently suspended; and a
     * suspend→unsuspend cycle restores state equivalent to a never-suspended
     * active Company.
     *
     * **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5**
     */
    // Feature: event-ticketing-platform, Property 4: Suspension gating and reversibility — storefront/event/sales/login available iff not suspended; suspend→unsuspend restores state equivalent to a never-suspended active Company
    public function test_surfaces_available_iff_not_suspended_and_unsuspend_restores_state(): void
    {
        $this->forAll($this->transitionSequenceGenerator())
            ->withMaxSize(20)
            ->then(function (array $transitions): void {
                // A control Company that is never suspended: its surfaces must
                // stay available throughout, giving the reference "active" state
                // an unsuspended Company must be equivalent to. (2.5)
                $control = Company::factory()->create(['status' => Company::STATUS_ACTIVE]);
                $controlUser = User::factory()->create([
                    'company_id' => $control->id,
                    'password' => Hash::make(self::PASSWORD),
                ]);

                // The Company under test starts active, then walks the random
                // suspend/unsuspend transitions.
                $company = Company::factory()->create(['status' => Company::STATUS_ACTIVE]);
                $user = User::factory()->create([
                    'company_id' => $company->id,
                    'password' => Hash::make(self::PASSWORD),
                ]);

                // Baseline: active from creation, all surfaces available.
                $this->assertSurfaces($company, $user, available: true);

                $suspended = false;
                foreach ($transitions as $suspend) {
                    $suspended = (bool) $suspend;
                    $company->update([
                        'status' => $suspended
                            ? Company::STATUS_SUSPENDED
                            : Company::STATUS_ACTIVE,
                    ]);

                    // Gating (iff): available exactly when not suspended.
                    // (2.1, 2.2, 2.3, 2.4)
                    $this->assertSurfaces($company, $user, available: ! $suspended);

                    // The never-suspended control is unaffected by another
                    // Company's transitions and stays fully available.
                    $this->assertSurfaces($control, $controlUser, available: true);
                }

                // Reversibility: force a final unsuspend and assert the Company
                // is now equivalent to the never-suspended control — all
                // surfaces available again. (2.5)
                if ($suspended) {
                    $company->update(['status' => Company::STATUS_ACTIVE]);
                }

                $this->assertSurfaces($company, $user, available: true);
                $this->assertSurfaces($control, $controlUser, available: true);
            });
    }
}
