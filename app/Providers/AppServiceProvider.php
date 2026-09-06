<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Branding\BrandingResolver;
use App\Services\Mail\FakeTicketMailer;
use App\Services\Mail\SmtpTicketMailer;
use App\Services\Mail\TicketMailer;
use App\Services\RoleAuthorization;
use App\Services\TicketPdfService;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripePaymentServiceStripeSdk;
use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The resolved tenant is shared for the whole request lifecycle so the
        // ResolveTenant middleware, the global company_id scope, and any
        // collaborators all observe the same active Company.
        $this->app->singleton(TenantContext::class);

        // The role permission matrix is stateless; a shared instance keeps the
        // matrix definition in one place for the gates and any callers.
        $this->app->singleton(RoleAuthorization::class);

        $this->bindStripePaymentService();
        $this->bindTicketMailer();
    }

    /**
     * Bind the ticket-email boundary. Every caller depends only on the
     * {@see TicketMailer} interface, so the transport can be swapped without
     * touching the fulfilment/job code (a future API mailer is a one-line
     * rebind). The real {@see SmtpTicketMailer} sends via the configured mailer
     * (cPanel SMTP) in every environment except testing; the testing
     * environment always gets the deterministic {@see FakeTicketMailer} — bound
     * as a shared singleton so a test can arrange/inspect the same instance the
     * job resolves — so no automated test ever sends a real email. (design →
     * MailService abstraction / Testing Strategy; Requirements 14.4, 14.5)
     */
    private function bindTicketMailer(): void
    {
        if ($this->app->environment('testing')) {
            $this->app->singleton(TicketMailer::class, FakeTicketMailer::class);

            return;
        }

        $this->app->singleton(TicketMailer::class, function ($app): SmtpTicketMailer {
            return new SmtpTicketMailer(
                $app->make(Mailer::class),
                $app->make(BrandingResolver::class),
                $app->make(TicketPdfService::class),
            );
        });
    }

    /**
     * Bind the Stripe boundary. The real SDK-backed implementation is used in
     * every environment except testing; the testing environment always gets the
     * deterministic fake so no automated test ever reaches the live Stripe API
     * and no card data is involved. (design → Stripe boundary / Testing Strategy)
     */
    private function bindStripePaymentService(): void
    {
        if ($this->app->environment('testing')) {
            // A shared fake so a test can arrange it (e.g. enable charges) and
            // the same instance is observed by the controller under test.
            $this->app->singleton(StripePaymentService::class, FakeStripePaymentService::class);

            return;
        }

        $this->app->singleton(StripeClient::class, function (): StripeClient {
            return new StripeClient((string) config('stripe.secret'));
        });

        $this->app->singleton(StripePaymentService::class, function ($app): StripePaymentServiceStripeSdk {
            return new StripePaymentServiceStripeSdk(
                $app->make(StripeClient::class),
                (string) config('stripe.webhook_secret'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRoleGates();
        $this->registerRateLimiters();
    }

    /**
     * Register the named rate limiters referenced by the `throttle:*` route
     * middleware. Limiters return a 429 once exceeded. (Security hardening)
     *
     *   - `login`  : credential submission — keyed on the submitted email plus
     *                the client IP so brute-force / credential-stuffing against
     *                one account is capped without letting one IP lock out every
     *                account. 5 attempts/min.
     *   - `auth`   : other unauthenticated auth actions (register, password-reset
     *                request + submit) — keyed on IP. 10 requests/min.
     *   - `public` : public storefront POSTs (checkout, ticket resend) — keyed on
     *                IP to limit reservation abuse and resend/email bombing.
     *                20 requests/min.
     */
    private function registerRateLimiters(): void
    {
        // Disable throttling under `testing` so high-volume suites (notably the
        // property-based checkout tests that fire many POSTs in a loop) are not
        // tripped by the production limits. The limiter wiring is still
        // exercised; only the ceiling is lifted.
        $unlimited = $this->app->environment('testing');

        RateLimiter::for('login', function (Request $request) use ($unlimited): Limit {
            if ($unlimited) {
                return Limit::none();
            }

            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth', function (Request $request) use ($unlimited): Limit {
            return $unlimited
                ? Limit::none()
                : Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('public', function (Request $request) use ($unlimited): Limit {
            return $unlimited
                ? Limit::none()
                : Limit::perMinute(20)->by((string) $request->ip());
        });
    }

    /**
     * Register one authorisation Gate per matrix action so every role-gated
     * action denies with an authorisation error unless the user's role permits
     * it. (Requirements 3.3–3.7, 3.10; design Property 6)
     *
     * A Super_Admin is granted every Company action via a `Gate::before` hook
     * so the separate super-admin surface is never blocked by the Company
     * matrix; the matrix itself excludes Super_Admins (no Company role).
     */
    private function registerRoleGates(): void
    {
        $authorization = $this->app->make(RoleAuthorization::class);

        Gate::before(function (User $user) {
            return $user->isSuperAdmin() ? true : null;
        });

        foreach (RoleAuthorization::actions() as $action) {
            Gate::define($action, static function (User $user) use ($authorization, $action): bool {
                return $authorization->authorize($user, $action);
            });
        }
    }
}
