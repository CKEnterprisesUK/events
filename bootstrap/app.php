<?php

use App\Http\Middleware\EnforceTenantScope;
use App\Http\Middleware\EnsureCompanyActive;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveDashboardTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SessionTimeout;
use App\Http\Middleware\VerifyStripeSignature;
use App\Services\ErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenant resolution + isolation for the public storefront / event
        // surface. `EnforceTenantScope` wraps the request so it can clear the
        // resolved tenant afterwards; `ResolveTenant` binds the Company from the
        // leading slug segment (404 on unmatched or suspended). Reserved
        // prefixes (`/`, `/dashboard`, `/admin`) are not placed in this group,
        // so they establish no Company. (Requirements 1.2-1.5, 1.7, 2.1, 2.2)
        $middleware->group('tenant', [
            EnforceTenantScope::class,
            ResolveTenant::class,
        ]);

        // Tenant resolution for the authenticated Company dashboard (reserved
        // `/dashboard` prefix, no slug segment). `EnforceTenantScope` wraps the
        // request so the resolved tenant is cleared afterwards;
        // `ResolveDashboardTenant` binds the authenticated user's own Company so
        // the global `company_id` scope constrains every dashboard query to
        // that Company. (Requirements 5.1, 3.8, 3.10)
        $middleware->group('dashboard.tenant', [
            EnforceTenantScope::class,
            ResolveDashboardTenant::class,
        ]);

        // Suspension guard for authenticated Company_Users. Applied via the
        // `company.active` alias alongside `auth` on authenticated route groups
        // so a suspended Company's users are logged out and denied on their
        // next request — suspension takes effect immediately. The login block
        // itself is handled inline in the LoginController. (Requirements 2.3, 2.4)
        // `session.timeout` invalidates authenticated sessions idle for >=30
        // minutes (Requirement 3.11), applied on authenticated route groups
        // alongside `auth` / `company.active` so the next request after the
        // idle window elapses forces re-authentication.
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'tenant.scope' => EnforceTenantScope::class,
            'company.active' => EnsureCompanyActive::class,
            // Guards the separate super-admin surface on `is_super_admin`, not
            // the Company role matrix. (Requirements 20.1, 20.7)
            'super.admin' => EnsureSuperAdmin::class,
            'session.timeout' => SessionTimeout::class,
            'stripe.webhook' => VerifyStripeSignature::class,
        ]);

        // Stripe posts webhooks to a fixed URL with no session/CSRF token, so
        // the webhook endpoint is excluded from CSRF verification; its
        // authenticity is established by the Stripe signature instead.
        // (Requirement 19.1)
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);

        // Tenant resolution must run before route-model binding so the global
        // `company_id` scope is active when `{event}` (and other Company-owned)
        // bindings are resolved — otherwise a bound model is looked up with no
        // tenant and 404s. Give the tenant middleware higher priority than
        // Laravel's SubstituteBindings. (Requirements 1.5, 5.4)
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnforceTenantScope::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveDashboardTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Production error capture. With APP_DEBUG=false an uncaught 500 would
        // otherwise render a generic framework page and vanish into the log.
        // Instead we persist the raw exception to `error_reports`, mint a short
        // customer-facing reference (ERR-XXXXXXXX), and show a branded page that
        // tells the visitor to quote that reference to support — where a
        // Super_Admin can look up the full trace at /admin/errors.
        //
        // Gated on: NOT debug (dev keeps Laravel's rich trace page), the
        // exception is a genuine server fault (not an HttpException such as
        // 404/403/419/422 which have their own friendly handling), and the
        // client is expecting HTML (JSON/API clients keep the default JSON
        // error shape via shouldRenderJsonWhen above).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (config('app.debug')) {
                return null; // dev: fall through to the detailed trace page.
            }

            if ($e instanceof HttpExceptionInterface) {
                return null; // 404/403/419/... keep their own error views.
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return null; // API clients keep the JSON error contract.
            }

            $report = app(ErrorReporter::class)->capture($e, $request, 500);

            return response()->view('errors.500', [
                'reference' => $report?->reference,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
