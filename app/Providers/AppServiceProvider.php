<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Branding\BrandingResolver;
use App\Services\Mail\FakeTicketMailer;
use App\Services\Mail\Graph\GraphMailClient;
use App\Services\Mail\Graph\GraphMailConfig;
use App\Services\Mail\Graph\GraphTransport;
use App\Services\Mail\MailTransportResolver;
use App\Services\Mail\SmtpTicketMailer;
use App\Services\Mail\TicketMailer;
use App\Services\AuditLogger;
use App\Services\RoleAuthorization;
use App\Services\TicketPdfService;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripePaymentServiceStripeSdk;
use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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

        // The audit logger resolves per-request actor/tenant/impersonation
        // context, so it shares the request-scoped TenantContext singleton.
        $this->app->singleton(AuditLogger::class);

        $this->bindStripePaymentService();
        $this->bindTicketMailer();
        $this->bindGraphMail();
    }

    /**
     * Bind the Microsoft Graph mail collaborators. {@see GraphMailConfig} is the
     * validated snapshot of `services.graph`; the {@see MailTransportResolver}
     * decides whether Graph or SMTP is the effective mailer. Both are shared so
     * the provider and the admin screen observe the same state. This only wires
     * up the objects — nothing sends via Graph until a Super_Admin selects it
     * AND the environment is configured (see {@see self::applyMailTransport()}).
     */
    private function bindGraphMail(): void
    {
        $this->app->singleton(GraphMailConfig::class, function ($app): GraphMailConfig {
            return GraphMailConfig::fromArray((array) $app['config']->get('services.graph', []));
        });

        $this->app->singleton(MailTransportResolver::class);
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
        $this->registerGraphMailTransport();
        $this->applyMailTransport();
    }

    /**
     * Register the `graph` mail transport with Laravel's mail manager so the
     * `graph` mailer in `config/mail.php` resolves to our
     * {@see \App\Services\Mail\Graph\GraphTransport}. This only *teaches* the
     * mailer how to build the transport; whether it is used is decided by
     * {@see self::applyMailTransport()}. Skipped under `testing` so no test can
     * reach the live Graph API.
     */
    private function registerGraphMailTransport(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        Mail::extend('graph', function (): GraphTransport {
            $config = $this->app->make(GraphMailConfig::class);

            $client = new GraphMailClient(
                $this->app->make(HttpFactory::class),
                $this->app->make(CacheRepository::class),
                $config,
            );

            return new GraphTransport($client, $config);
        });
    }

    /**
     * Set the effective default mailer from the Super_Admin's persisted choice.
     * When Graph is selected AND configured the default becomes `graph`;
     * otherwise it stays on the existing SMTP mailer. Reading through the
     * resolver keeps this decision in one place and safe before the migration
     * is applied. Skipped under `testing`, which keeps the array/fake transport.
     */
    private function applyMailTransport(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        $active = $this->app->make(MailTransportResolver::class)->activeMailer();

        $this->app['config']->set('mail.default', $active);
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
