<?php

namespace App\Http\Middleware;

use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any non-TLS request on the API group before any handler runs so no
 * credential, token, or tenant-scoped payload ever crosses a plaintext
 * connection. (Requirement 12.2)
 *
 * The API is stateless and token-based: a Bearer Access_Token (and, on
 * authenticated routes, the password at sign-in) rides in the request, so a
 * plaintext hop would leak it. We therefore refuse the request outright rather
 * than redirecting — an API client must talk TLS from the first byte; a 426
 * tells it exactly why.
 *
 * Local development and the test suite run over plain HTTP (no TLS terminates
 * in front of them), so the guard is lifted there to keep API routes
 * exercisable. Every deployed environment (production, staging/pre-prod, ...)
 * enforces TLS. This mirrors the project's existing `environment('local'|
 * 'testing')` escape hatches in {@see AppServiceProvider}.
 */
class EnforceHttps
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure() && ! $this->shouldAllowInsecure()) {
            abort(
                Response::HTTP_UPGRADE_REQUIRED,
                'This API requires a secure (TLS/HTTPS) connection.'
            );
        }

        return $next($request);
    }

    /**
     * Only local development and the test suite — which have no TLS terminating
     * in front of them — are allowed to serve the API over plain HTTP. Every
     * deployed environment must arrive over TLS.
     */
    private function shouldAllowInsecure(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
