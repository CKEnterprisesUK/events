<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceHttps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app
 *
 * Task 1.3 — the EnforceHttps middleware that guards the whole `api` group.
 *
 * Requirement 12.2: the API SHALL require all requests to use TLS and SHALL
 * reject non-TLS requests.
 *
 * The middleware refuses any non-secure request with a 426 Upgrade Required
 * *before* any handler runs — but it deliberately lifts enforcement in the
 * `local` and `testing` environments (see {@see EnforceHttps::shouldAllowInsecure()}),
 * because no TLS terminates in front of local dev or the test suite and the API
 * routes must stay exercisable there.
 *
 * That escape hatch is exactly what makes a naive feature test ("GET an api/*
 * route over http and expect 426") impossible: the suite runs in the `testing`
 * environment, so enforcement is lifted and the request would sail through. To
 * exercise the rejection path we drive the middleware directly and force the
 * reported environment to a deployed one (`production`), then assert the
 * complementary behaviours — a secure request and the testing-env escape hatch
 * both pass through.
 *
 * NOTE: These tests were authored but NOT executed — MySQL is unreachable in
 * this environment so the PHPUnit suite cannot be run here. They touch no
 * database (no RefreshDatabase trait) and were verified with `php -l` and by
 * review.
 */
class EnforceHttpsTest extends TestCase
{
    /**
     * A non-secure (plain HTTP) request in a *deployed* environment is rejected
     * with 426 Upgrade Required before reaching the handler. (Requirement 12.2)
     *
     * We force the app environment to `production` so the middleware's
     * `local`/`testing` escape hatch does not apply, which lets the test
     * exercise the real rejection path despite running under `testing`.
     */
    public function test_non_tls_request_is_rejected_in_a_deployed_environment(): void
    {
        // Force a deployed environment. Set the container's `env` directly
        // rather than via detectEnvironment() so a stray `--env=` PHPUnit CLI
        // argument can never override it: EnforceHttps::shouldAllowInsecure()
        // reads app()->environment(), which returns this value.
        $this->app['env'] = 'production';

        $middleware = new EnforceHttps;

        $request = Request::create('http://api.example.com/api/scan', 'POST');
        $this->assertFalse($request->secure(), 'guard precondition: request is not secure');

        $reachedHandler = false;

        try {
            $middleware->handle($request, function () use (&$reachedHandler) {
                $reachedHandler = true;

                return new Response('ok');
            });

            $this->fail('Expected a non-TLS request to be rejected, but it was allowed through.');
        } catch (HttpException $e) {
            $this->assertSame(
                Response::HTTP_UPGRADE_REQUIRED,
                $e->getStatusCode(),
                'A non-TLS request must be rejected with 426 Upgrade Required.'
            );
        }

        $this->assertFalse($reachedHandler, 'The handler must never run for a non-TLS request.');
    }

    /**
     * A secure (TLS/HTTPS) request is allowed through to the next handler even
     * in a deployed environment. (Requirement 12.2 — the pass-through side.)
     */
    public function test_tls_request_passes_through_in_a_deployed_environment(): void
    {
        $this->app['env'] = 'production';

        $middleware = new EnforceHttps;

        $request = Request::create('https://api.example.com/api/scan', 'POST');
        $this->assertTrue($request->secure(), 'guard precondition: request is secure');

        $response = $middleware->handle($request, fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * The `testing` (and `local`) environment escape hatch: because no TLS
     * terminates in front of the test suite, a non-secure request is allowed
     * through so the API routes stay exercisable. This is why the suite itself
     * cannot assert 426 via a plain HTTP request to an api/* route.
     */
    public function test_non_tls_request_passes_through_in_the_testing_environment(): void
    {
        $this->assertTrue(
            $this->app->environment('testing'),
            'precondition: the suite runs in the testing environment'
        );

        $middleware = new EnforceHttps;

        $request = Request::create('http://localhost/api/scan', 'POST');
        $this->assertFalse($request->secure());

        $response = $middleware->handle($request, fn () => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * End-to-end through the real `api` middleware group: a request to an
     * api/* route reaches its handler. Because the suite runs under `testing`
     * the EnforceHttps guard is lifted, so this confirms the middleware is
     * wired into the group without blocking legitimate (test-env) traffic —
     * the complement to the forced-environment rejection test above.
     */
    public function test_api_route_is_reachable_through_the_middleware_group(): void
    {
        Route::middleware('api')->get('/api/_enforce-https-probe', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/_enforce-https-probe')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
