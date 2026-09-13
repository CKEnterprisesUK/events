<?php

namespace App\Jobs;

use App\Http\Controllers\StripeConnectController;
use App\Models\Company;
use App\Services\Stripe\StripeAccountCapabilities;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Refreshes the connected-account onboarding/verification snapshot
 * (`stripe_charges_enabled`, `stripe_payouts_enabled`,
 * `stripe_details_submitted`, `stripe_disabled_reason`, `stripe_requirements`)
 * for every Company that has a connected Stripe account, by re-reading the
 * account through {@see StripePaymentService::retrieveAccountCapabilities()}.
 * (Requirements 11.3, 11.4)
 *
 * ## Why this job exists
 *
 * The requirement detail is normally kept current by two event-driven paths:
 * the return from onboarding, and the `account.updated` webhook. Neither fires
 * for a Company that connected in the PAST and hasn't touched onboarding since
 * — so an already-blocked customer would see "charges not enabled" with an
 * EMPTY requirements list (the columns default to null/empty on the migration),
 * never learning WHAT Stripe is waiting on. This sweeper backfills that state
 * so existing blocked customers immediately see the outstanding items on their
 * Payments page, and it doubles as a reconciliation pass in case a webhook is
 * ever missed.
 *
 * ## What it selects
 *
 * Every Company with a non-null `stripe_account_id`, across all Companies (no
 * tenant scope) since it runs in the scheduler with no resolved Company. A
 * per-run cap keeps a single invocation bounded on shared hosting; ordering by
 * id and offsetting is not needed because the set of connected accounts is
 * small and the read is cheap and idempotent — the next run simply re-reads.
 *
 * ## Idempotency & safety
 *
 * Reading an account mutates nothing on Stripe. Persisting only overwrites the
 * Platform's own snapshot columns with Stripe's current truth, so repeated runs
 * converge rather than drift. A read that throws for one account is logged and
 * skipped so one bad account never blocks the rest of the batch.
 *
 * ## Scheduling
 *
 * Registered as `stripe:refresh-accounts` in `routes/console.php`, run
 * SYNCHRONOUSLY from cron like the other sweepers (shared cPanel hosting
 * disables `proc_open`, so `schedule:run` cannot be used). A daily cron is
 * ample; run it once by hand right after deploy to refresh existing accounts.
 */
class RefreshStripeAccountStateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum connected accounts to refresh in a single run, so one invocation
     * stays bounded on shared hosting. Remaining accounts are picked up next run.
     */
    public const BATCH_LIMIT = 200;

    public function __construct(private readonly int $limit = self::BATCH_LIMIT) {}

    /**
     * A breakdown of the last run, so the caller can report WHY the refreshed
     * count is what it is rather than a bare number. A "0 refreshed" with, say,
     * `matched = 1` and `failed = 1` means the account was found but the Stripe
     * read threw (see the log), which is a very different situation from
     * `matched = 0` (no connected account in the database at all).
     *
     * @var array{matched: int, refreshed: int, skipped_blank: int, failed: int}
     */
    public array $summary = [
        'matched' => 0,
        'refreshed' => 0,
        'skipped_blank' => 0,
        'failed' => 0,
    ];

    /**
     * Re-read and persist the connected-account state for each connected Company.
     *
     * @return int the number of Companies whose state was refreshed this run.
     */
    public function handle(StripePaymentService $stripe): int
    {
        // Before the companies table exists (fresh install mid-migration) this
        // is a safe no-op, mirroring the other sweepers' schema guard.
        if (! Schema::hasTable('companies')) {
            return 0;
        }

        $companies = Company::query()
            ->whereNotNull('stripe_account_id')
            ->orderBy('id')
            ->limit($this->limit)
            ->get();

        $this->summary = [
            'matched' => $companies->count(),
            'refreshed' => 0,
            'skipped_blank' => 0,
            'failed' => 0,
        ];

        foreach ($companies as $company) {
            $accountId = (string) $company->stripe_account_id;

            // whereNotNull matches a stored empty string too — treat a blank id
            // as "not really connected" and count it separately so a run that
            // found only blank ids is not mistaken for "no accounts at all".
            if ($accountId === '') {
                $this->summary['skipped_blank']++;

                continue;
            }

            try {
                $capabilities = $stripe->retrieveAccountCapabilities($accountId);
            } catch (Throwable $e) {
                // One unreadable account (e.g. deauthorised, or a transient
                // Stripe error) must not abort the whole batch. Log and move on;
                // the next run retries it.
                $this->summary['failed']++;

                Log::warning('Could not refresh Stripe account state for company.', [
                    'company_id' => $company->id,
                    'stripe_account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->applyCapabilities($company, $capabilities);
            $company->save();
            $this->summary['refreshed']++;
        }

        return $this->summary['refreshed'];
    }

    /**
     * Copy the capability/verification snapshot onto the Company (without
     * saving), mirroring {@see StripeConnectController}'s
     * own persistence so the sweeper and the onboarding-return path store an
     * identical set of fields. (Requirements 11.3, 11.4)
     */
    private function applyCapabilities(Company $company, StripeAccountCapabilities $capabilities): void
    {
        $company->stripe_charges_enabled = $capabilities->chargesEnabled;
        $company->stripe_payouts_enabled = $capabilities->payoutsEnabled;
        $company->stripe_details_submitted = $capabilities->detailsSubmitted;
        $company->stripe_disabled_reason = $capabilities->disabledReason;
        $company->stripe_requirements = $capabilities->requirements();
    }
}
