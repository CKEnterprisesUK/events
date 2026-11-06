<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\Ticket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GDPR data-subject operations over a Customer's stored personal data, scoped
 * to the requesting Company. (Requirement 22)
 *
 * A "Customer" is identified by their email address — the Platform holds no
 * separate Customer entity, so their data is the set of Orders placed with that
 * `customer_email`, plus the Tickets and consent selections hanging off those
 * Orders. Because every one of those models is Company-owned (the
 * {@see BelongsToCompany} trait registers the global
 * `company_id` scope), each query this service runs is automatically
 * constrained to the resolved tenant. A Company therefore only ever exports or
 * anonymises Orders belonging to its own Company — another Company's identical
 * `customer_email` never matches. This is the GDPR facet of the tenant
 * isolation property. (Requirements 22.5, 1.5, design Property 1)
 *
 * Two operations:
 *   - {@see export()} — produce every stored personal field the Company holds
 *     about the Customer, including their captured consents. (Requirement 22.1)
 *   - {@see anonymise()} — scrub the Customer's identifiable personal data
 *     (name, email) from every matching Order while RETAINING the transactional
 *     records (Order reference, money figures, fees, status) required for
 *     reconciliation, and preserving the consent audit trail. (Requirement 22.2)
 *   - {@see retentionDeletionDate()} — the date a Customer's personal data is
 *     due to be auto-anonymised under the Company's retention policy, derived
 *     from their last order. Surfaced in the export.
 *   - {@see anonymiseExpiredForCompany()} — the automated retention sweep's
 *     entry point: anonymise every Customer of a Company whose LAST order is
 *     older than the Company's retention window. Runs OUTSIDE tenant scope
 *     (the sweep has no resolved tenant), so it scopes `company_id` explicitly.
 */
class GdprService
{
    /**
     * The placeholder written over the customer name/email on anonymisation.
     * A fixed, non-identifiable sentinel so no personal data survives while the
     * NOT NULL transactional columns stay populated. (Requirement 22.2)
     */
    public const ANONYMISED_NAME = '[anonymised]';

    public const ANONYMISED_EMAIL = 'anonymised@redacted.invalid';

    /**
     * Order statuses that mean the Customer still holds (or may hold) tickets,
     * so their data is needed until the Event has happened.
     *
     * @var list<string>
     */
    private const LIVE_ORDER_STATUSES = [
        Order::STATUS_RESERVED,
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
        Order::STATUS_DISPUTED,
    ];

    /**
     * Export every stored personal field the requesting Company holds about the
     * Customer identified by `$customerEmail`, as a structured array. Runs under
     * the resolved tenant, so only the Company's own Orders are ever included.
     * (Requirements 22.1, 22.5)
     *
     * Also surfaces, at the top level:
     *   - `marketing_preference` — the Customer's LATEST marketing opt-in
     *     across their Orders (true/false), or null if they never saw the
     *     marketing consent. (Requirement 22.4 — show what they agreed to.)
     *   - `retention` — the Company's retention window and the date this
     *     Customer's personal data is due to be auto-anonymised, derived from
     *     their last order, so the export states exactly how long the data is
     *     kept and when it is removed.
     *
     * @return array{
     *     customer_email: string,
     *     marketing_preference: bool|null,
     *     retention: array{
     *         retention_months: int,
     *         last_order_at: string|null,
     *         deletion_due_at: string|null
     *     },
     *     orders: list<array<string, mixed>>
     * }
     */
    public function export(string $customerEmail): array
    {
        $orders = Order::query()
            ->where('customer_email', $customerEmail)
            ->with(['consents', 'tickets'])
            ->orderBy('id')
            ->get();

        return [
            'customer_email' => $customerEmail,
            // The latest marketing opt-in this Customer expressed, across every
            // Order, or null if they never saw the marketing consent.
            'marketing_preference' => $this->latestMarketingPreference($orders),
            // How long the data is kept and when it is due to be anonymised.
            'retention' => $this->retentionSummary($orders),
            'orders' => $orders->map(fn (Order $order): array => [
                'order_reference' => $order->order_reference,
                'event_id' => $order->event_id,
                // Stored personal fields held about the Customer.
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                // Transactional context (included so the export is complete).
                'status' => $order->status,
                'ticket_subtotal_minor' => $order->ticket_subtotal_minor,
                'booking_fee_minor' => $order->booking_fee_minor,
                'application_fee_minor' => $order->application_fee_minor,
                'order_total_minor' => $order->order_total_minor,
                'created_at' => optional($order->created_at)->toIso8601String(),
                'tickets' => $order->tickets->map(fn (Ticket $ticket): array => [
                    'id' => $ticket->id,
                    'ticket_type_id' => $ticket->ticket_type_id,
                    'status' => $ticket->status,
                ])->all(),
                // Surface the consent selections captured at checkout so the
                // Customer sees exactly what they agreed to. (Requirement 22.4)
                'consents' => $order->consents->map(fn (OrderConsent $consent): array => [
                    'consent_key' => $consent->consent_key,
                    'accepted' => $consent->accepted,
                    'captured_at' => optional($consent->captured_at)->toIso8601String(),
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * Anonymise the Customer's personal data across every Order the requesting
     * Company holds for `$customerEmail`, overwriting the identifiable
     * name/email with fixed placeholders while leaving the transactional
     * records (reference, money, fees, status) untouched for reconciliation.
     * Returns the number of Orders anonymised. Scoped to the tenant, so a
     * Company can only ever anonymise its own Customer data. (Requirements 22.2,
     * 22.5)
     */
    public function anonymise(string $customerEmail): int
    {
        return DB::transaction(function () use ($customerEmail): int {
            $orders = Order::query()
                ->where('customer_email', $customerEmail)
                ->get();

            foreach ($orders as $order) {
                // Overwrite ONLY the personal fields; every transactional
                // column (order_reference, *_minor money, status, Stripe ids)
                // is deliberately left in place. (Requirement 22.2)
                $order->update([
                    'customer_name' => self::ANONYMISED_NAME,
                    'customer_email' => self::ANONYMISED_EMAIL,
                ]);
            }

            return $orders->count();
        });
    }

    /**
     * Anonymise every Customer of the given Company whose personal data has
     * aged past the Company's retention window — the automated retention
     * sweep's core. A Customer "expires" only once ALL their Orders are older
     * than the cutoff (the clock runs from their LAST order), so an active
     * repeat Customer is never scrubbed mid-relationship.
     *
     * Runs OUTSIDE the tenant global scope — the scheduled sweep has no resolved
     * Company — so it scopes `company_id` EXPLICITLY on every query. Already-
     * anonymised Orders (email already the sentinel) are skipped, making the
     * sweep idempotent: re-running it anonymises nothing new. The transactional
     * records are left untouched exactly as in {@see anonymise()}.
     *
     * @param  int  $companyId  The Company whose expired Customers to sweep.
     * @param  Carbon  $cutoff  Customers whose last order is strictly before
     *                          this are anonymised.
     * @return int The number of Orders anonymised.
     */
    public function anonymiseExpiredForCompany(int $companyId, Carbon $cutoff): int
    {
        // The set of customer emails whose LAST order predates the cutoff. Doing
        // the max(created_at) aggregation in SQL means a Customer with even one
        // order newer than the cutoff is excluded wholesale. The sentinel email
        // is excluded so already-anonymised Customers are not re-processed.
        //
        // The clock for each order is its creation date, EXCEPT a live order
        // (reserved / paid / free / disputed) for an Event that is still to
        // happen and has not been cancelled: that runs from the Event's start,
        // so a ticket bought years ahead is never scrubbed before the Event.
        // Cancelled / refunded / expired / voided orders (or a cancelled
        // Event) fall back to the order date, so they never extend retention.
        $live = "'".implode("','", self::LIVE_ORDER_STATUSES)."'";
        $relevantAt = "CASE WHEN orders.status IN ({$live}) "
            .'AND events.cancelled_at IS NULL '
            .'AND events.starts_at IS NOT NULL '
            .'AND events.starts_at > orders.created_at '
            .'THEN events.starts_at ELSE orders.created_at END';

        $expiredEmails = Order::query()
            ->withoutGlobalScopes()
            ->leftJoin('events', 'events.id', '=', 'orders.event_id')
            ->where('orders.company_id', $companyId)
            ->where('orders.customer_email', '!=', self::ANONYMISED_EMAIL)
            ->groupBy('orders.customer_email')
            ->havingRaw("MAX({$relevantAt}) < ?", [$cutoff])
            ->pluck('orders.customer_email');

        if ($expiredEmails->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($companyId, $cutoff, $expiredEmails): int {
            $anonymised = 0;

            foreach ($expiredEmails as $email) {
                // Re-scope to this Company explicitly on the update too, so the
                // sweep can never touch another tenant's identical email. Guard
                // the cutoff again inside the transaction so a race that lands a
                // new order between the read and the write leaves that Customer
                // alone.
                $orders = Order::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('customer_email', $email)
                    ->get();

                // Belt-and-braces: skip if a fresh order arrived after the read.
                $lastOrderAt = $this->lastRelevantAt($orders);
                if ($lastOrderAt !== null && $lastOrderAt >= $cutoff) {
                    continue;
                }

                foreach ($orders as $order) {
                    $order->update([
                        'customer_name' => self::ANONYMISED_NAME,
                        'customer_email' => self::ANONYMISED_EMAIL,
                    ]);
                    $anonymised++;
                }
            }

            return $anonymised;
        });
    }

    /**
     * The date the retention clock runs from for these Orders: the latest of
     * each order's creation date and, for a live order on an upcoming,
     * non-cancelled Event, that Event's start. Mirrors the SQL in
     * {@see anonymiseExpiredForCompany()}.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function lastRelevantAt($orders): ?Carbon
    {
        if ($orders->isEmpty()) {
            return null;
        }

        $events = Event::withoutGlobalScopes()
            ->whereIn('id', $orders->pluck('event_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $orders
            ->map(function (Order $order) use ($events): Carbon {
                $created = Carbon::parse($order->created_at);
                $event = $events->get($order->event_id);

                if ($event !== null
                    && in_array($order->status, self::LIVE_ORDER_STATUSES, true)
                    && $event->cancelled_at === null
                    && $event->starts_at !== null
                    && $event->starts_at->greaterThan($created)) {
                    return $event->starts_at->copy();
                }

                return $created;
            })
            ->max();
    }

    /**
     * The Customer's latest marketing opt-in across the given Orders: the
     * `accepted` flag of their most recently captured `marketing` consent, or
     * null if they never saw the marketing consent.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function latestMarketingPreference($orders): ?bool
    {
        $latest = $orders
            ->flatMap(fn (Order $order) => $order->consents)
            ->filter(fn (OrderConsent $consent): bool => $consent->consent_key === OrderConsent::KEY_MARKETING)
            ->sortByDesc(fn (OrderConsent $consent) => [$consent->captured_at, $consent->id])
            ->first();

        return $latest === null ? null : (bool) $latest->accepted;
    }

    /**
     * The retention summary for the export: the window in force for the
     * Customer's Company, their last-order date, and the date their personal
     * data is due to be auto-anonymised (last order + window). Returns the
     * default window with null dates when the Customer has no orders.
     *
     * @param  Collection<int, Order>  $orders
     * @return array{retention_months: int, last_order_at: string|null, deletion_due_at: string|null}
     */
    private function retentionSummary($orders): array
    {
        $company = $orders->isEmpty()
            ? null
            : Company::withoutGlobalScopes()->find($orders->first()->company_id);

        $months = $company?->effectiveRetentionMonths()
            ?? Company::RETENTION_MONTHS_DEFAULT;

        $lastOrderAt = $orders->max('created_at');
        $relevantAt = $this->lastRelevantAt($orders);
        $deletionDueAt = $relevantAt !== null
            ? Carbon::parse($relevantAt)->addMonths($months)
            : null;

        return [
            'retention_months' => $months,
            'last_order_at' => optional($lastOrderAt)->toIso8601String(),
            'deletion_due_at' => optional($deletionDueAt)->toIso8601String(),
        ];
    }
}
