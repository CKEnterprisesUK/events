<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * GDPR data-subject operations over a Customer's stored personal data, scoped
 * to the requesting Company. (Requirement 22)
 *
 * A "Customer" is identified by their email address — the Platform holds no
 * separate Customer entity, so their data is the set of Orders placed with that
 * `customer_email`, plus the Tickets and consent selections hanging off those
 * Orders. Because every one of those models is Company-owned (the
 * {@see \App\Models\Concerns\BelongsToCompany} trait registers the global
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
     * Export every stored personal field the requesting Company holds about the
     * Customer identified by `$customerEmail`, as a structured array. Runs under
     * the resolved tenant, so only the Company's own Orders are ever included.
     * (Requirements 22.1, 22.5)
     *
     * @return array{
     *     customer_email: string,
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
}
