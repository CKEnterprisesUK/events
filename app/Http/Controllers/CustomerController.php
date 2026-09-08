<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Services\AuditLogger;
use App\Services\GdprService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Company-dashboard Customers surface: a roster of the Company's Customers
 * derived from their Orders (the Platform holds no separate Customer entity — a
 * Customer is identified by `customer_email`), plus per-Customer detail and the
 * GDPR data-subject tools (export / anonymise) that act on that Customer.
 *
 * The roster/detail are Admin-gated via `ACTION_MANAGE_ORDERS` (the same
 * authority that manages the underlying Orders). The GDPR export/anonymise
 * actions are gated via `ACTION_MANAGE_GDPR` (held by the Owner and Admin),
 * because data-subject handling is a data-controller compliance
 * responsibility trusted only to those roles. Every query runs
 * under the `dashboard.tenant` group, so the global `company_id` scope confines
 * results to the acting Company — a Company only ever sees or acts on its own
 * Customers. (Requirements 10.1, 22.1, 22.2, 22.5)
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly GdprService $gdpr,
        private readonly AuditLogger $audit,
    ) {}

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

        // Optional event filter: only customers who ordered for this event. The
        // dropdown is populated from the Company's own events (tenant-scoped),
        // and a foreign/unknown id simply matches nothing.
        $eventId = (int) $request->query('event', 0);
        $eventId = $eventId > 0 ? $eventId : null;

        // Optional marketing-preference filter, keyed off each customer's LATEST
        // captured marketing consent: 'in' (opted in), 'out' (opted out or
        // never opted in). Any other value = no filter.
        $marketing = (string) $request->query('marketing', '');
        $marketing = in_array($marketing, ['in', 'out'], true) ? $marketing : '';

        // Correlated subquery: the customer's most recent marketing consent
        // (accepted 1/0), NULL when they never saw the marketing opt-in. Ordered
        // by capture time then id so the newest selection wins — this is the
        // "latest marketing preference". Both tables carry company_id; the outer
        // Order query is already tenant-scoped, and joining orders o2 back on the
        // same company_id keeps the subquery scoped to the acting Company too.
        $latestMarketing = '('
            .'select oc.accepted from order_consents oc '
            .'inner join orders o2 on o2.id = oc.order_id '
            .'where o2.customer_email = orders.customer_email '
            .'and o2.company_id = orders.company_id '
            .'and oc.consent_key = ? '
            .'order by oc.captured_at desc, oc.id desc limit 1'
            .') as marketing_opt_in';

        $customers = Order::query()
            ->selectRaw('customer_email')
            ->selectRaw('MAX(customer_name) as customer_name')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COUNT(DISTINCT event_id) as events_count')
            ->selectRaw('MAX(created_at) as last_order_at')
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN order_total_minor ELSE 0 END) as spend_minor',
                [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED],
            )
            ->selectRaw($latestMarketing, [OrderConsent::KEY_MARKETING])
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('customer_name', 'like', $like)
                        ->orWhere('customer_email', 'like', $like);
                });
            })
            ->when($eventId !== null, function ($query) use ($eventId): void {
                // Restrict to customers who have at least one order for the
                // chosen event. Grouping is by email, so a whereExists keeps the
                // per-customer aggregates (count/spend across ALL their orders)
                // intact while still limiting WHO appears.
                $query->whereExists(function ($sub) use ($eventId): void {
                    $sub->select(DB::raw(1))
                        ->from('orders as oe')
                        ->whereColumn('oe.customer_email', 'orders.customer_email')
                        ->whereColumn('oe.company_id', 'orders.company_id')
                        ->where('oe.event_id', $eventId);
                });
            })
            ->when($marketing !== '', function ($query) use ($marketing): void {
                // Filter on the latest marketing preference. 'in' = the newest
                // marketing consent is accepted; 'out' = it is declined or the
                // customer never saw/accepted the opt-in (NULL / 0).
                if ($marketing === 'in') {
                    $query->having('marketing_opt_in', '=', 1);
                } else {
                    $query->havingRaw('marketing_opt_in IS NULL OR marketing_opt_in = 0');
                }
            })
            ->groupBy('customer_email', 'company_id')
            ->orderByDesc('last_order_at')
            ->paginate(25)
            ->withQueryString();

        // The event dropdown: the Company's events, newest first. Tenant-scoped
        // by the global company scope, so only the acting Company's events show.
        $events = Event::query()
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get(['id', 'name']);

        return view('dashboard.customers.index', [
            'customers' => $customers,
            'search' => $search,
            'events' => $events,
            'eventId' => $eventId,
            'marketing' => $marketing,
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
            'canManageGdpr' => Gate::allows(RoleAuthorization::ACTION_MANAGE_GDPR),
        ]);
    }

    /**
     * Export this Customer's stored personal data as a downloadable JSON
     * document. Gated to the Owner/Admin (data-controller responsibility) and
     * tenant-scoped. (Requirements 22.1, 22.5)
     */
    public function export(string $customer): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_GDPR);

        $email = $this->decodeEmail($customer);
        $export = $this->gdpr->export($email);

        // Record THAT an export happened, never the exported data. The customer
        // is referenced by a salted hash of the email, not the email itself, so
        // the audit trail holds no recoverable customer PII.
        $this->audit->record(
            action: AuditLog::GDPR_CUSTOMER_EXPORTED,
            summary: 'Exported a customer\'s personal data',
            context: ['customer_ref' => $this->customerRef($email)],
        );

        return response()
            ->json($export, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="gdpr-export.json"');
    }

    /**
     * Export the WHOLE Customer roster as a CSV — one row per distinct
     * `customer_email` the Company holds Orders for, with order count, confirmed
     * spend and last-order date.
     *
     * This is a bulk export of personal data, so it carries the SAME
     * data-controller authority as the per-Customer export: gated to the
     * Owner/Admin via `ACTION_MANAGE_GDPR` (Box_Office, which can view the
     * roster, deliberately cannot bulk-export it), and tenant-scoped so a
     * Company only ever exports its own Customers. (Requirements 22.1, 22.5)
     *
     * The export is audited as a privacy event recording only THAT it happened
     * and how many Customers it covered — never the exported names/emails
     * themselves — keeping the audit trail free of the very PII being exported.
     */
    public function exportAll(): StreamedResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_GDPR);

        // Same aggregate shape as the roster (index), unpaginated, so the file
        // matches exactly what the Company sees on screen.
        $customers = Order::query()
            ->selectRaw('customer_email')
            ->selectRaw('MAX(customer_name) as customer_name')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('MAX(created_at) as last_order_at')
            ->selectRaw(
                'SUM(CASE WHEN status IN (?, ?) THEN order_total_minor ELSE 0 END) as spend_minor',
                [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED],
            )
            ->groupBy('customer_email')
            ->orderByDesc('last_order_at')
            ->get();

        $this->audit->record(
            action: AuditLog::GDPR_CUSTOMERS_EXPORTED,
            summary: 'Exported the full customer list ('.$customers->count().' customer(s))',
            context: ['customers_exported' => $customers->count()],
        );

        $filename = 'customers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($customers): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, ['Name', 'Email', 'Orders', 'Confirmed spend (minor units)', 'Last order']);

            foreach ($customers as $customer) {
                fputcsv($out, [
                    $customer->customer_name,
                    $customer->customer_email,
                    (int) $customer->orders_count,
                    (int) $customer->spend_minor,
                    $customer->last_order_at,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Anonymise (delete) this Customer's personal data across every matching
     * Order, retaining the transactional records. Gated to the Owner/Admin and
     * tenant-scoped. (Requirements 22.2, 22.5)
     */
    public function anonymise(string $customer): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_GDPR);

        $email = $this->decodeEmail($customer);
        $count = $this->gdpr->anonymise($email);

        // Record THAT an anonymisation happened and how many orders it touched,
        // referencing the customer by a salted hash rather than the (now gone)
        // email. No recoverable PII enters the trail.
        $this->audit->record(
            action: AuditLog::GDPR_CUSTOMER_ANONYMISED,
            summary: 'Anonymised a customer\'s personal data on '.$count.' order(s)',
            context: [
                'customer_ref' => $this->customerRef($email),
                'orders_affected' => $count,
            ],
        );

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
     * A stable, non-reversible reference to a Customer for the audit trail: a
     * keyed HMAC of the normalised email. It lets two audit rows about the same
     * Customer be correlated without the trail ever holding the email itself
     * (GDPR data-minimisation on the log). Keyed on APP_KEY so it cannot be
     * recomputed off-platform from a guessed email list.
     */
    private function customerRef(string $email): string
    {
        $normalised = strtolower(trim($email));

        return substr(hash_hmac('sha256', $normalised, (string) config('app.key')), 0, 32);
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
