<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CustomerController;
use App\Models\Order;
use App\Services\RoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant-scoped customer search for the native iOS scanner app. The Platform
 * holds no separate Customer entity — a Customer is identified by their
 * `customer_email` — so this endpoint re-expresses the same aggregate the web
 * {@see CustomerController::index()} roster is built from: one row per distinct
 * `customer_email`, with that Customer's order count and confirmed spend, over
 * an optional free-text name/email search.
 *
 * It is gated via `ACTION_MANAGE_ORDERS` — the same authority that manages the
 * underlying Orders and the web Customers surface (held by Owner, Admin,
 * Box_Office, Accountant). A role without that action is denied 403 and no data
 * is read (Requirements 8.1, 8.3). The query runs under the `api.tenant` group,
 * so the global `company_id` scope confines results to the Active_Company — a
 * Company only ever searches its own Customers and no cross-company rows leak
 * (Requirements 8.2, 12.3).
 *
 * The web roster carries extra event/marketing filters and a last-order date;
 * this API deliberately keeps to the fields the client needs — name, email,
 * order count and confirmed spend — leaving the richer web surface untouched.
 */
class CustomerApiController extends Controller
{
    /**
     * Search the Active_Company's Customers by name/email and return a
     * paginated list of `{name, email, orders_count, confirmed_spend_minor}`
     * rows, newest-ordering Customers first. An empty search term returns the
     * whole tenant-scoped roster, paginated.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        $search = trim((string) $request->query('q', ''));

        $customers = Order::query()
            ->selectRaw('customer_email')
            ->selectRaw('MAX(customer_name) as customer_name')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('MAX(created_at) as last_order_at')
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN order_total_minor ELSE 0 END) as spend_minor',
                [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED],
            )
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('customer_name', 'like', $like)
                        ->orWhere('customer_email', 'like', $like);
                });
            })
            ->groupBy('customer_email', 'company_id')
            ->orderByDesc('last_order_at')
            ->paginate(25)
            ->withQueryString();

        return response()->json([
            'customers' => collect($customers->items())->map(fn (Order $customer) => [
                'name' => $customer->customer_name,
                'email' => $customer->customer_email,
                'orders_count' => (int) $customer->orders_count,
                'confirmed_spend_minor' => (int) $customer->spend_minor,
            ])->values(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }
}
