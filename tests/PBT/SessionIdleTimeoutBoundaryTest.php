<?php

namespace Tests\PBT;

use App\Http\Middleware\SessionTimeout;
use App\Models\User;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Property-based test for the session idle timeout boundary (design Property
 * 7).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The SessionTimeout middleware (Requirement 3.11) invalidates an
 * authenticated session once it has been idle for 30 minutes or longer,
 * forcing re-authentication before any further action; a request inside the
 * window proceeds and refreshes `last_activity_at`. The boundary is inclusive
 * and measured in whole seconds: idle >= 1800s requires re-auth, idle < 1800s
 * does not.
 *
 * This property generates random idle durations concentrated around the
 * 1800-second boundary (both just under and at/over) and asserts, for both the
 * web and JSON surfaces, that re-authentication is required if and only if the
 * idle duration is at least 30 minutes. Carbon::setTestNow pins "now" so the
 * elapsed idle duration is exact and deterministic.
 */
class SessionIdleTimeoutBoundaryTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The inclusive idle threshold in seconds: 30 minutes. (Requirement 3.11)
     */
    private const IDLE_THRESHOLD_SECONDS = SessionTimeout::IDLE_TIMEOUT_MINUTES * 60;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Idle durations (in seconds) drawn tightly around the 1800-second
     * boundary — a window of +/- 120s on each side — so the property hammers
     * the exact edge where the iff decision flips, including 1799, 1800 and
     * 1801. A handful of far-from-boundary values keep the space honest.
     */
    private function idleDurationSecondsGenerator(): Generator
    {
        return Generator\oneOf(
            // The critical band straddling the boundary. 1800 - 120 = 1680.
            Generator\map(
                fn (int $offset): int => self::IDLE_THRESHOLD_SECONDS - 120 + $offset,
                Generator\choose(0, 240)
            ),
            // Clearly inside the active window (0s .. 1679s).
            Generator\choose(0, self::IDLE_THRESHOLD_SECONDS - 121),
            // Clearly expired (1921s .. 4 hours).
            Generator\choose(self::IDLE_THRESHOLD_SECONDS + 121, 4 * 60 * 60),
        );
    }

    /**
     * Run the SessionTimeout middleware for an authenticated user whose
     * last activity was $idleSeconds ago, and report whether re-auth was
     * required. `$json` selects the API surface (401) vs the web surface
     * (redirect to login).
     *
     * Returns a tuple: [reAuthRequired (bool), user (User)].
     */
    private function runIdle(int $idleSeconds, bool $json): array
    {
        $now = Carbon::create(2024, 1, 1, 12, 0, 0);
        Carbon::setTestNow($now);

        $user = User::factory()->create([
            'last_activity_at' => $now->copy()->subSeconds($idleSeconds),
        ]);
        Auth::login($user);

        $middleware = new SessionTimeout;
        $request = Request::create($json ? '/api/orders' : '/dashboard', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        if ($json) {
            $request->headers->set('Accept', 'application/json');
        }

        $next = fn () => response('ok');

        try {
            $response = $middleware->handle($request, $next);
        } catch (HttpException $e) {
            // JSON surface denies via a 401 abort.
            return [$e->getStatusCode() === 401, $user];
        }

        // Web surface denies via a redirect to the login route; a pass-through
        // returns the wrapped 'ok' response.
        $reAuthRequired = $response->isRedirect(route('login'));

        return [$reAuthRequired, $user];
    }

    /**
     * Property 7: Session idle timeout boundary — for any idle duration,
     * re-authentication is required if and only if the idle duration is at
     * least 30 minutes (1800 seconds); otherwise the request proceeds and the
     * idle timer resets to now.
     *
     * **Validates: Requirements 3.11**
     */
    // Feature: event-ticketing-platform, Property 7: Session idle timeout boundary — require re-auth iff idle duration >= 30 minutes
    public function test_reauth_required_iff_idle_at_least_thirty_minutes(): void
    {
        $this->forAll(
            $this->idleDurationSecondsGenerator(),
            Generator\bool(),
        )
            ->then(function (int $idleSeconds, bool $json): void {
                $expectedReAuth = $idleSeconds >= self::IDLE_THRESHOLD_SECONDS;

                [$reAuthRequired, $user] = $this->runIdle($idleSeconds, $json);

                $this->assertSame(
                    $expectedReAuth,
                    $reAuthRequired,
                    sprintf(
                        'idle %ds (%s surface): re-auth should be %s',
                        $idleSeconds,
                        $json ? 'json' : 'web',
                        $expectedReAuth ? 'required' : 'not required',
                    ),
                );

                if ($expectedReAuth) {
                    // Re-auth path invalidates the session — no user remains.
                    $this->assertGuest();
                } else {
                    // Active path refreshes the idle timer to the request time.
                    $this->assertTrue(
                        $user->fresh()->last_activity_at->equalTo(Carbon::now()),
                        'An in-window request must refresh last_activity_at to now.',
                    );
                }

                Auth::logout();
            });
    }
}
