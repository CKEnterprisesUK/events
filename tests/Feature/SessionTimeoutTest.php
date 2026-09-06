<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionTimeout;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 4.2 — the SessionTimeout middleware enforcing the 30-minute idle
 * timeout (Requirement 3.11): a session inactive for 30 minutes or longer is
 * invalidated and re-authentication is required before any further action;
 * activity within the window keeps the session alive and refreshes the timer.
 *
 * Tests exercise durations around the 30-minute boundary.
 */
class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_request_within_idle_window_is_allowed(): void
    {
        // Requirement 3.11 — 29m59s idle is under the boundary: still active.
        $user = User::factory()->create([
            'last_activity_at' => Carbon::now()->subMinutes(30)->addSecond(),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_request_at_or_beyond_boundary_is_invalidated(): void
    {
        // Requirement 3.11 — exactly 30 minutes idle already requires re-auth.
        $user = User::factory()->create([
            'last_activity_at' => Carbon::now()->subMinutes(30),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_request_well_beyond_boundary_is_invalidated(): void
    {
        // Requirement 3.11 — clearly-expired session is denied.
        $user = User::factory()->create([
            'last_activity_at' => Carbon::now()->subHours(2),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_activity_within_window_refreshes_last_activity(): void
    {
        // Requirement 3.11 — an allowed request resets the idle timer to now.
        $now = Carbon::create(2024, 1, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        $user = User::factory()->create([
            'last_activity_at' => $now->copy()->subMinutes(10),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertTrue(
            $user->fresh()->last_activity_at->equalTo($now),
            'last_activity_at should be refreshed to the current request time.'
        );
    }

    public function test_boundary_case_just_under_thirty_minutes_still_active(): void
    {
        // Requirement 3.11 — 29m30s idle is still within the window.
        $now = Carbon::create(2024, 1, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        $user = User::factory()->create([
            'last_activity_at' => $now->copy()->subMinutes(29)->subSeconds(30),
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_user_without_recorded_activity_is_seeded_and_allowed(): void
    {
        // A user with no last_activity_at is treated as active and stamped.
        $now = Carbon::create(2024, 1, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        $user = User::factory()->create(['last_activity_at' => null]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertNotNull($user->fresh()->last_activity_at);
        $this->assertTrue($user->fresh()->last_activity_at->equalTo($now));
    }

    public function test_middleware_passes_through_active_user_at_boundary_edge(): void
    {
        // Middleware-level: just under the boundary passes through and refreshes.
        $now = Carbon::create(2024, 1, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        $user = User::factory()->create([
            'last_activity_at' => $now->copy()->subMinutes(30)->addSecond(),
        ]);
        Auth::login($user);

        $response = $this->runMiddleware('/dashboard', 'GET', function () {
            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertTrue($user->fresh()->last_activity_at->equalTo($now));
    }

    public function test_middleware_denies_json_request_with_401_when_idle(): void
    {
        // Requirement 3.11 — JSON/API surface is denied with 401 once idle.
        $user = User::factory()->create([
            'last_activity_at' => Carbon::now()->subMinutes(45),
        ]);
        Auth::login($user);

        $blocked = false;
        try {
            $this->runMiddleware('/api/orders', 'GET', fn () => response('nope'), json: true);
        } catch (HttpException $e) {
            $blocked = true;
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertTrue($blocked, 'Expected an idle JSON request to be denied.');
        $this->assertGuest();
    }

    public function test_middleware_allows_unauthenticated_request(): void
    {
        // No authenticated user: nothing to time out, request proceeds.
        $called = false;
        $response = $this->runMiddleware('/dashboard', 'GET', function () use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
        $this->assertSame('ok', $response->getContent());
    }

    private function runMiddleware(string $uri, string $method, Closure $next, bool $json = false)
    {
        $middleware = new SessionTimeout;
        $request = Request::create($uri, $method);
        $request->setLaravelSession($this->app['session']->driver());

        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        return $middleware->handle($request, $next);
    }
}
