<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\GdprService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Company-dashboard Customers surface: a roster of the Company's Customers
 * derived from their Orders (the Platform holds no separate Customer entity — a
 * Customer is identified by `customer_email`), plus per-Customer detail and the
 * GDPR data-subject tools (export / anonymise) that act on that Customer.
 *
 * The roster/detail are Admin-gated via `ACTION_MANAGE_ORDERS` (the same
 * authority that manages the underlying Orders). The GDPR export/anonymise
 * actions are Owner-gated via `ACTION_MANAGE_SETTINGS`, because data-subject
 * handling is a data-controller compliance responsibility. Every query runs
 * under the `dashboard.tenant` group, so the global `company_id` scope confines
 * results to the acting Company — a Company only ever sees or acts on its own
 * Customers. (Requirements 10.1, 22.1, 22.2, 22.5)
 */
class CustomerController extends Controller
{
    public function __construct(private readonly GdprService $gdpr) {}

    /**
     * The Customer roster: one row per distinct `customer_email` the Company
     * holds Orders for, with order count, confirmed spend and last-order date.
     * Supports a free-text search over name/email. Anonymised customers are
     * grouped under the fixed sentinel like any other. (Requirement 10.1)
     */
    public function index(Request $request): View
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
            ->groupBy('customer_email')
            ->orderByDesc('last_order_at')
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.customers.index', [
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    /**
     * One Customer's detail: every Order they placed with the Company (newest
     * first), with tickets and consent records eager-loaded, plus the GDPR
     * tools. The Customer is addressed by a URL-safe base64 encoding of their
     * email in the path. (Requirements 10.5, 22.1, 22.4)
     */
    public function show(string $customer): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        $email = $this->decodeEmail($customer);

        $orders = Order::query()
            ->with(['event', 'tickets.ticketType', 'consents'])
            ->where('customer_email', $email)
            ->latest()
            ->get();

        if ($orders->isEmpty()) {
            throw new NotFoundHttpException;
        }

        return view('dashboard.customers.show', [
            'email' => $email,
            'token' => $customer,
            'name' => $orders->first()->customer_name,
            'orders' => $orders,
            'confirmedSpendMinor' => (int) $orders
                ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
                ->sum('order_total_minor'),
            'canManageGdpr' => Gate::allows(RoleAuthorization::ACTION_MANAGE_SETTINGS),
        ]);
    }

    /**
     * Export this Customer's stored personal data as a downloadable JSON
     * document. Owner-gated (data-controller responsibility) and tenant-scoped.
     * (Requirements 22.1, 22.5)
     */
    public function export(string $customer): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        $email = $this->decodeEmail($customer);
        $export = $this->gdpr->export($email);

        return response()
            ->json($export, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="gdpr-export.json"');
    }

    /**
     * Anonymise (delete) this Customer's personal data across every matching
     * Order, retaining the transactional records. Owner-gated and
     * tenant-scoped. (Requirements 22.2, 22.5)
     */
    public function anonymise(string $customer): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        $email = $this->decodeEmail($customer);
        $count = $this->gdpr->anonymise($email);

        // After anonymisation the email is rewritten to the sentinel, so the
        // original token no longer resolves — send the Owner back to the roster.
        return redirect()
            ->route('dashboard.customers.index')
            ->with('status', "Anonymised personal data on {$count} order(s).");
    }

    /**
     * A URL-safe token for a Customer email, used to build detail/GDPR links.
     */
    public static function tokenFor(string $email): string
    {
        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    }

    /**
     * Decode a Customer token back to the email, or 404 on a malformed token.
     */
    private function decodeEmail(string $token): string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        if ($decoded === false || $decoded === '') {
            throw new NotFoundHttpException;
        }

        return $decoded;
    }
}
