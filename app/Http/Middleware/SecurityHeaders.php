<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline browser-hardening headers to every response.
 *
 * - Clickjacking: pages may only be framed by the same origin. A response that
 *   already sets its own Content-Security-Policy (the public `/embed/*` widgets,
 *   which deliberately allow any host to frame them) is left alone.
 * - `nosniff`, a strict referrer policy, and a Permissions-Policy that keeps the
 *   camera available to this site (the web QR scanner) and blocks the microphone.
 * - HSTS on secure requests outside local development and testing.
 *
 * Headers already set by a controller are never overwritten.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");

            if (! $headers->has('X-Frame-Options')) {
                $headers->set('X-Frame-Options', 'SAMEORIGIN');
            }
        }

        if (! $headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(self), microphone=()');
        }

        if ($request->secure() && ! app()->environment(['local', 'testing']) && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
