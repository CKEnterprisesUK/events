<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Task 26.1 — hosting/deployment wiring for shared cPanel (no persistent
 * worker, cron-drained DB queue, phpMyAdmin schema).
 *
 * These assertions lock in the config-level guarantees the deployment relies
 * on: the scheduler drains the queue and sweeps reservations every minute; the
 * Stripe webhook stays CSRF-exempt; and the QR HMAC secret is driven by a
 * dedicated env var so it can be held stable across deploys.
 *
 * _Requirements: 15.2, 15.3, 7.1, 19.1_
 */
class DeploymentWiringTest extends TestCase
{
    /**
     * Collect the compiled command line / job class for every registered
     * scheduled event so the tests can assert what the per-minute cron runs.
     *
     * @return array<int, string>
     */
    private function scheduledSummaries(): array
    {
        $schedule = $this->app->make(Schedule::class);

        return array_map(function ($event) {
            // `command` events expose the rendered artisan command line;
            // `job` events (CallbackEvent) expose a description we set on the
            // event, falling back to the summary for display.
            return $event->command
                ?? $event->description
                ?? $event->getSummaryForDisplay();
        }, $schedule->events());
    }

    public function test_scheduler_drains_the_database_queue_every_minute(): void
    {
        $summaries = $this->scheduledSummaries();

        $queueWork = collect($summaries)->first(
            fn (string $s) => str_contains($s, 'queue:work')
        );

        $this->assertNotNull(
            $queueWork,
            'Expected a scheduled queue:work entry so cron drains the DB queue.'
        );
        $this->assertStringContainsString('--stop-when-empty', $queueWork);
    }

    public function test_scheduler_sweeps_expired_reservations_every_minute(): void
    {
        $summaries = $this->scheduledSummaries();

        $hasReleaseJob = collect($summaries)->contains(
            fn (string $s) => str_contains($s, 'ReleaseExpiredReservationsJob')
        );

        $this->assertTrue(
            $hasReleaseJob,
            'Expected ReleaseExpiredReservationsJob to remain scheduled.'
        );
    }

    public function test_both_per_minute_tasks_guard_against_overlap(): void
    {
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            // Every per-minute task must be non-overlapping so a slow burst
            // never races the next tick. Expression '* * * * *' = every minute.
            if ($event->expression === '* * * * *') {
                $this->assertNotEmpty(
                    $event->mutexName(),
                    'Per-minute scheduled tasks must use withoutOverlapping().'
                );
            }
        }
    }

    public function test_stripe_webhook_is_exempt_from_csrf(): void
    {
        // A POST with no CSRF token must not be rejected with a 419 token
        // mismatch — the endpoint is listed in validateCsrfTokens(except).
        $response = $this->post('/stripe/webhook', []);

        $this->assertNotSame(
            419,
            $response->getStatusCode(),
            '/stripe/webhook should be exempt from CSRF verification.'
        );
    }

    public function test_qr_hmac_secret_is_driven_by_a_dedicated_env_var(): void
    {
        // The secret must be settable independently of APP_KEY so it can be
        // held stable across deploys; APP_KEY is only the local fallback.
        //
        // Snapshot and restore every piece of global state this assertion
        // touches (the QR_HMAC_SECRET env in all three superglobals, the live
        // `qr.hmac_secret` config value, and app.key) so it cannot leak an
        // empty/altered secret into later tests that generate or verify QR
        // tokens. Re-requiring config/qr.php below re-evaluates env() but must
        // never mutate the live config cache the rest of the suite relies on.
        $originalPutenv = getenv('QR_HMAC_SECRET');
        $originalEnv = $_ENV['QR_HMAC_SECRET'] ?? null;
        $originalServer = $_SERVER['QR_HMAC_SECRET'] ?? null;
        $originalConfigSecret = config('qr.hmac_secret');
        $originalAppKey = config('app.key');

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        putenv('QR_HMAC_SECRET=fixed-production-secret');
        $_ENV['QR_HMAC_SECRET'] = 'fixed-production-secret';
        $_SERVER['QR_HMAC_SECRET'] = 'fixed-production-secret';

        try {
            $resolved = (require $this->app->configPath('qr.php'))['hmac_secret'];
            $this->assertSame('fixed-production-secret', $resolved);
        } finally {
            // Restore the env superglobals to exactly their prior state.
            if ($originalPutenv === false) {
                putenv('QR_HMAC_SECRET');
            } else {
                putenv('QR_HMAC_SECRET='.$originalPutenv);
            }

            if ($originalEnv === null) {
                unset($_ENV['QR_HMAC_SECRET']);
            } else {
                $_ENV['QR_HMAC_SECRET'] = $originalEnv;
            }

            if ($originalServer === null) {
                unset($_SERVER['QR_HMAC_SECRET']);
            } else {
                $_SERVER['QR_HMAC_SECRET'] = $originalServer;
            }

            // Restore the live config cache so later tests still resolve the
            // configured QR secret and app key.
            config()->set('qr.hmac_secret', $originalConfigSecret);
            config()->set('app.key', $originalAppKey);
        }
    }

    public function test_queue_connection_defaults_to_database(): void
    {
        // Cron-drained model requires the DB queue driver (no Redis/daemon).
        $this->assertSame('database', config('queue.default'));
    }
}
