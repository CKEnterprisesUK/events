<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyActive;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 3.1 — enforcing Company suspension on login and existing
 * sessions via the EnsureCompanyActive guard (wired into the LoginController
 * and the authenticated `company.active` route group).
 *
 * Requirements:
 *  - 2.3 a Company_User of a suspended Company cannot log in
 *  - 2.4 a suspended Company cannot operate (its authenticated users are denied)
 *  - 2.5 unsuspending restores login/session for that Company's users
 *
 * Company_Users carry a `company_id` permanently from task 4.1's `users`
 * schema, so these tests exercise the real User→Company relationship and
 * middleware wiring directly.
 */
class CompanySuspensionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_of_active_company_can_log_in(): void
    {
        // Requirement 2.3 (positive) / 2.5 — an active Company's user logs in.
        $company = Company::factory()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'password' => Hash::make('secret-password'),
            // Dismiss the post-login MFA nudge so this suspension-gating test
            // observes the plain login landing (/dashboard).
            'mfa_prompt_dismissed_at' => now(),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    public function test_user_of_suspended_company_cannot_log_in(): void
    {
        // Requirement 2.3 — valid credentials, but suspended Company blocks login.
        $company = Company::factory()->suspended()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
    }

    public function test_authenticated_user_is_denied_when_their_company_is_suspended(): void
    {
        // Requirement 2.4 — an already-authenticated session is torn down on the
        // next request once the Company is suspended.
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        // Session established while active.
        $this->actingAs($user)->get('/dashboard')->assertOk();

        // Super_Admin suspends the Company.
        $company->update(['status' => Company::STATUS_SUSPENDED]);

        // A genuine subsequent request rehydrates the user from the session, so
        // use a fresh instance rather than the relation-cached one from above.
        $response = $this->actingAs($user->fresh())->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_unsuspended_company_restores_login(): void
    {
        // Requirement 2.5 — unsuspending restores the ability to log in.
        $company = Company::factory()->suspended()->create();
        $user = User::factory()->create([
            'company_id' => $company->id,
            'password' => Hash::make('secret-password'),
            'mfa_prompt_dismissed_at' => now(),
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/login');
        $this->assertGuest();

        $company->update(['status' => Company::STATUS_ACTIVE]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_user_without_a_company_is_not_blocked(): void
    {
        // Resilience: a user with no owning Company (e.g. a Super_Admin) is not
        // affected by the suspension guard.
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_middleware_passes_through_active_company_user(): void
    {
        // Middleware unit-level check: an active Company's user passes through.
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        Auth::login($user);

        $middleware = new EnsureCompanyActive;
        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        $called = false;
        $response = $middleware->handle($request, function () use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
        $this->assertSame('ok', $response->getContent());
    }

    public function test_middleware_blocks_suspended_company_user_on_json_request(): void
    {
        // Requirement 2.4 — JSON/API surface is denied with 403 for a suspended
        // Company's authenticated user (e.g. a sales/checkout API path).
        $company = Company::factory()->suspended()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        Auth::login($user);

        $middleware = new EnsureCompanyActive;
        $request = Request::create('/api/tickets', 'POST');
        $request->headers->set('Accept', 'application/json');
        $request->setLaravelSession($this->app['session']->driver());

        $blocked = false;
        try {
            $middleware->handle($request, fn () => response('should not reach'));
        } catch (HttpException $e) {
            $blocked = true;
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue($blocked, 'Expected the suspended-company request to be blocked.');
        $this->assertGuest();
    }
}
