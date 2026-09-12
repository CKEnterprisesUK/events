<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super-admin controller for Platform-wide transaction oversight and the total
 * Application_Fees earned. (Requirements 20.1, 20.2)
 *
 * Unlike every Company-facing surface, this is deliberately NOT tenant-scoped:
 * a Super_Admin sees all Companies' transactions across the whole Platform. The
 * super-admin surface (`/admin`) resolves no active Company, and `Order` carries
 * the global `company_id` tenant scope — which, with no Company resolved, would
 * force `1 = 0` and hide every row. So this controller queries Orders with
 * `withoutGlobalScopes()` to reach across all Companies, then reports the total
 * Application_Fees as the sum of the recorded `application_fee_minor` on paid /
 * confirmed Orders Platform-wide. (Requirement 20.2, Property 26)
 *
 * All money is in integer minor currency units.
 */
class TransactionController extends Controller
{
    /**
     * The Order statuses that represent settled money and therefore contribute
     * to the total Application_Fees earned: a Customer paid, or a free Order was
     * confirmed. Reserved holds, expired/cancelled/voided, and refunded/disputed
     * Orders are excluded — they earned the Platform no realised fee.
     * (Requirements 20.2, 13.7, 17.x)
     *
     * @var list<string>
     */
    private const FEE_EARNING_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    /**
     * Show all Companies' transactions and the total Application_Fees earned
     * across the whole Platform. (Requirements 20.1, 20.2)
     */
    public function index(): View
    {
        // Cross-Company: bypass the tenant global scope so every Company's
        // Orders are visible from the (Company-less) super-admin surface.
        $orders = Order::query()
            ->withoutGlobalScopes()
            ->orderByDesc('id')
            ->get();

        $companyNames = Company::query()->pluck('name', 'id');

        // Total Application_Fees earned = sum of recorded application fee on the
        // fee-earning (paid/confirmed) Orders across all Companies. (20.2)
        $totalApplicationFeesMinor = (int) $orders
            ->whereIn('status', self::FEE_EARNING_STATUSES)
            ->sum('application_fee_minor');

        $transactions = $orders->map(fn (Order $order) => [
            'id' => $order->id,
            'company_id' => $order->company_id,
            'company_name' => (string) $companyNames->get($order->company_id, 'Unknown company'),
            'order_reference' => $order->order_reference,
            'status' => $order->status,
            'order_total_minor' => $order->order_total_minor,
            'order_total_gbp' => Money::gbp((int) $order->order_total_minor),
            'application_fee_minor' => $order->application_fee_minor,
            'application_fee_gbp' => Money::gbp((int) $order->application_fee_minor),
        ])->all();

        return view('admin.transactions.index', [
            'transactions' => $transactions,
            'totalApplicationFeesMinor' => $totalApplicationFeesMinor,
            'totalApplicationFeesGbp' => Money::gbp($totalApplicationFeesMinor),
            'companyCount' => $companyNames->count(),
        ]);
    }

    /**
     * Stream the Platform-wide transactions as a CSV for the operator's own
     * accounting: one row per Order across all Companies, plus a header row
     * carrying the total Application_Fees earned. Cross-Company like
     * {@see index()} (bypasses the tenant scope). Read-only. Money is written in
     * major units so the file opens cleanly in a spreadsheet. (Requirements 20.1,
     * 20.2)
     */
    public function export(): StreamedResponse
    {
        $orders = Order::query()
            ->withoutGlobalScopes()
            ->orderByDesc('id')
            ->get();

        $companyNames = Company::query()->pluck('name', 'id');

        $totalApplicationFeesMinor = (int) $orders
            ->whereIn('status', self::FEE_EARNING_STATUSES)
            ->sum('application_fee_minor');

        $major = fn (int $minor): string => number_format($minor / 100, 2, '.', '');

        $filename = 'platform-transactions-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($orders, $companyNames, $totalApplicationFeesMinor, $major): void {
            $out = fopen('php://output', 'wb');

            // Summary line: total platform fees earned across all fee-earning orders.
            fputcsv($out, ['Total platform fees earned (GBP)', $major($totalApplicationFeesMinor)]);
            fputcsv($out, []);

            fputcsv($out, [
                'Order ID', 'Company', 'Order reference', 'Status',
                'Order total', 'Platform fee', 'Stripe fee',
            ]);

            foreach ($orders as $order) {
                fputcsv($out, [
                    $order->id,
                    (string) $companyNames->get($order->company_id, 'Unknown company'),
                    $order->order_reference,
                    $order->status,
                    $major((int) $order->order_total_minor),
                    $major((int) $order->application_fee_minor),
                    // Actual Stripe fee where captured; blank when not yet known.
                    $order->stripe_fee_minor === null ? '' : $major((int) $order->stripe_fee_minor),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
