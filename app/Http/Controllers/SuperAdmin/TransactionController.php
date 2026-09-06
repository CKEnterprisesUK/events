<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Contracts\View\View;

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
            'application_fee_minor' => $order->application_fee_minor,
        ])->all();

        return view('admin.transactions.index', [
            'transactions' => $transactions,
            'totalApplicationFeesMinor' => $totalApplicationFeesMinor,
            'companyCount' => $companyNames->count(),
        ]);
    }
}
