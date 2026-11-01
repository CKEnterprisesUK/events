<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Services\GdprService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces each Company's customer-data retention policy by anonymising the
 * personal data (name/email) of Customers whose LAST order has aged past that
 * Company's retention window. The transactional records (order reference,
 * money, fees, status) are always retained — only the identifiable personal
 * data is scrubbed, exactly like a manual GDPR anonymise. (Requirement 22.2;
 * data-minimisation)
 *
 * ## Why this job exists
 *
 * A Company holds a Customer's name/email on every Order indefinitely unless
 * someone manually runs a GDPR anonymise. That is more personal data, kept
 * longer, than data-minimisation allows. This sweep gives each Company a
 * self-service retention window (default 24 months, chosen during onboarding)
 * and automatically scrubs personal data once a Customer's relationship has
 * gone cold — defined as their LAST order being older than the window, so an
 * active repeat Customer is never scrubbed mid-relationship.
 *
 * ## Per-company window (unlike the fixed audit retention)
 *
 * The audit-log prune uses one fixed platform-wide window. Here every Company
 * sets its own {@see Company::effectiveRetentionMonths()} (floored at 6 months,
 * defaulting to 24), so the cutoff is computed PER COMPANY. The job iterates
 * Companies and applies each one's own cutoff.
 *
 * ## Scheduling
 *
 * Production runs on shared cPanel hosting where `proc_open` is disabled, so —
 * like {@see PruneAuditLogsJob} — this job is invoked DIRECTLY from a cron entry
 * via an artisan command that runs it synchronously
 * (`php artisan customers:anonymise-expired`), NOT via `Schedule::command()`. A
 * daily cron is ample; the windows move slowly.
 *
 * ## Safety / idempotency
 *
 *   - Runs OUTSIDE the tenant global scope (no resolved Company in the
 *     scheduler) and scopes `company_id` EXPLICITLY on every query, so one
 *     Company's sweep can never touch another's identical customer email.
 *   - Only Customers whose LAST order predates the cutoff are anonymised; a
 *     single fresh order keeps the whole Customer intact.
 *   - Already-anonymised Customers (sentinel email) are skipped, so re-running
 *     the sweep anonymises nothing new.
 *   - A `Schema::hasTable` guard no-ops a partial-migration state rather than
 *     erroring (mirrors the other sweepers).
 */
class AnonymiseExpiredCustomerDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Per-run summary, readable by the artisan command after an in-process run:
     * how many Companies were swept and how many Orders were anonymised.
     *
     * @var array{companies_swept: int, orders_anonymised: int}
     */
    public array $summary = [
        'companies_swept' => 0,
        'orders_anonymised' => 0,
    ];

    /**
     * Sweep every Company's expired Customer personal data.
     *
     * @return int The total number of Orders anonymised across all Companies.
     */
    public function handle(GdprService $gdpr, AuditLogger $audit): int
    {
        // The customer data lives on the orders table; if it does not exist yet
        // (partial migration) there is nothing to sweep.
        if (! Schema::hasTable('companies') || ! Schema::hasTable('orders')) {
            return 0;
        }

        $totalAnonymised = 0;
        $companiesSwept = 0;

        // Iterate every Company (no tenant scope on companies) and apply each
        // one's own retention window. Chunk so a large tenant base never loads
        // every Company row at once.
        Company::query()
            ->withoutGlobalScopes()
            ->orderBy('id')
            ->chunkById(100, function ($companies) use ($gdpr, $audit, &$totalAnonymised, &$companiesSwept): void {
                foreach ($companies as $company) {
                    $cutoff = now()->subMonths($company->effectiveRetentionMonths());

                    $anonymised = $gdpr->anonymiseExpiredForCompany($company->id, $cutoff);

                    if ($anonymised > 0) {
                        $companiesSwept++;
                        $totalAnonymised += $anonymised;

                        // Record THAT the automated sweep ran for this Company
                        // and how many Orders it touched — never any PII. This
                        // is a system action (no actor), so the Company is set
                        // explicitly.
                        $audit->recordSystem(
                            action: AuditLog::GDPR_RETENTION_SWEEP,
                            summary: 'Auto-anonymised customer data on '.$anonymised.' order(s) past the '
                                .$company->effectiveRetentionMonths().'-month retention window',
                            context: [
                                'orders_affected' => $anonymised,
                                'retention_months' => $company->effectiveRetentionMonths(),
                            ],
                            companyId: $company->id,
                        );
                    }
                }
            });

        $this->summary = [
            'companies_swept' => $companiesSwept,
            'orders_anonymised' => $totalAnonymised,
        ];

        return $totalAnonymised;
    }
}
