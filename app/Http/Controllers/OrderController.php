<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\Order;
use App\Services\CompTicketService;
use App\Services\OrderCancellationService;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Company-dashboard controller for cancelling and refunding Orders and for
 * issuing complimentary ("comp") tickets.
 *
 * Cancellation is gated on the Admin `ACTION_CANCEL_ORDER` permission,
 * refunding on `ACTION_REFUND_ORDER`, and comp issuance on `ACTION_ISSUE_COMP`;
 * the role matrix grants all three to the Admin role (and, via the
 * `Gate::before` bypass, Super_Admins). Any other role is denied with a 403
 * leaving the Order/Event and its Tickets unchanged. (Requirements 17.1, 18.1)
 *
 * Orders and Events are Company-owned: the dashboard runs under the reserved
 * `/dashboard` prefix where the URL carries no slug, so the `dashboard.tenant`
 * middleware binds the authenticated user's Company onto the TenantContext. The
 * global `company_id` scope then constrains route-model binding — a request for
 * another Company's Order or Event surfaces as 404 with no modification.
 * (Requirements 1.5, 17.1, 18.1)
 *
 * Cancel/refund delegate to {@see OrderCancellationService}, the single
 * idempotent path shared with the refund/dispute webhooks so a dashboard refund
 * and a later `charge.refunded` webhook converge on one refunded Order without
 * double-applying. Comp issuance delegates to {@see CompTicketService}, which
 * consumes capacity through the same reservation path as a paid sale so a comp
 * can never oversell. (Requirements 17.2, 17.3, 17.4, 18.3)
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderCancellationService $cancellation,
        private readonly CompTicketService $comps,
    ) {}

    /**
     * List the Company's Orders across every Event, most recent first. Supports
     * a free-text search over the order reference and customer name/email, and
     * a status filter. Scoped to the acting Company by the tenant global scope
     * (the dashboard binds the Company onto the TenantContext), so a Company
     * only ever sees its own Orders. (Requirements 10.1, 17.1)
     */
    public function index(Request $request): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $orders = Order::query()
            ->with('event')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('order_reference', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_email', 'like', $like);
                });
            })
            ->when($this->isKnownStatus($status), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.orders.index', [
            'orders' => $orders,
            'search' => $search,
            'status' => $status,
            'statuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Show one Order with its Tickets (and their Ticket_Types), consent records
     * and check-in status. Cross-Company Orders never match the tenant scope
     * and surface as 404. (Requirements 10.5, 16.1, 22.4)
     */
    public function show(Order $order): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        $order->load(['event', 'tickets.ticketType', 'consents']);

        return view('dashboard.orders.show', [
            'order' => $order,
            'terminal' => $this->isTerminal($order),
        ]);
    }

    /**
     * Cancel an Order: void its Tickets so the QR fails at scan and return its
     * held capacity (reserved or sold) for resale. No money moves — a paid
     * Order is refunded via {@see refund()}. (Requirements 17.1, 17.3)
     */
    public function cancel(Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CANCEL_ORDER);

        $this->cancellation->cancel($order);

        return back()->with('status', 'Order cancelled.');
    }

    /**
     * Refund an Order: issue the Stripe refund on the Company's connected
     * account for a paid Order, then mark the Order refunded, void its Tickets,
     * and return its sold capacity. Idempotent — refunding an already-terminal
     * Order is a no-op and issues no second refund. (Requirements 17.1, 17.2,
     * 17.3, 17.4)
     */
    public function refund(Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_REFUND_ORDER);

        $this->cancellation->refund($order);

        return back()->with('status', 'Order refunded.');
    }

    /**
     * Issue complimentary tickets for an Event: create a confirmed
     * (`free_confirmed`) Order at zero money with one Ticket per comp ticket,
     * generate the QR, and enqueue the ticket email — no payment is taken. The
     * comp consumes capacity through the same reservation path as a paid sale,
     * so it counts against both the Ticket_Type capacity and the Event's
     * overall capacity and can never oversell: an over-request is rejected
     * cleanly with nothing held and no Order created. (Requirements 18.1, 18.2,
     * 18.3)
     *
     * Gated on the Admin `ACTION_ISSUE_COMP` permission; any other role is
     * denied with a 403 leaving capacity and Orders unchanged. The Event is
     * resolved within the acting user's Company by the `dashboard.tenant` group
     * (foreign Events 404). (Requirements 3.4, 18.1)
     */
    public function issueComp(Request $request, Event $event, TenantContext $tenantContext): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_ISSUE_COMP);

        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        $data = $this->validatedComp($request);

        try {
            $this->comps->issue(
                company: $company,
                event: $event,
                quantities: $this->quantitiesFrom($data['items']),
                recipientName: $data['recipient_name'],
                recipientEmail: $data['recipient_email'],
            );
        } catch (InsufficientCapacityException $e) {
            // Capacity would be exceeded — reject cleanly, nothing issued.
            // (Requirement 18.3)
            throw ValidationException::withMessages([
                'items' => 'Not enough capacity remains to issue these complimentary tickets.',
            ]);
        } catch (\InvalidArgumentException $e) {
            // A requested Ticket_Type does not belong to this Event.
            throw ValidationException::withMessages([
                'items' => 'One or more selected ticket types are not available for this event.',
            ]);
        }

        return back()->with('status', 'Complimentary tickets issued.');
    }

    /**
     * Validate the comp issuance payload: a recipient name (1–200) and email
     * (1–254), and 1–50 line items with positive integer quantities.
     * (Requirement 18.1)
     *
     * @return array<string, mixed>
     */
    private function validatedComp(Request $request): array
    {
        return $request->validate([
            'recipient_name' => ['required', 'string', 'min:1', 'max:200'],
            'recipient_email' => ['required', 'string', 'min:1', 'max:254', 'email'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
    }

    /**
     * Collapse the submitted line items into a Ticket_Type id => quantity map,
     * summing duplicate lines for the same type.
     *
     * @param  array<int, array{ticket_type_id:int, quantity:int}>  $items
     * @return array<int, int>
     */
    private function quantitiesFrom(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $id = (int) $item['ticket_type_id'];
            $qty = (int) $item['quantity'];

            $quantities[$id] = ($quantities[$id] ?? 0) + $qty;
        }

        return $quantities;
    }

    /**
     * The Order statuses a client can filter by, as value => human label.
     *
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $statuses = [
            Order::STATUS_PAID,
            Order::STATUS_FREE_CONFIRMED,
            Order::STATUS_RESERVED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_DISPUTED,
            Order::STATUS_EXPIRED,
            Order::STATUS_VOIDED,
        ];

        $options = [];

        foreach ($statuses as $value) {
            $options[$value] = ucfirst(str_replace('_', ' ', $value));
        }

        return $options;
    }

    /**
     * Whether the given value is one of the filterable Order statuses.
     */
    private function isKnownStatus(string $status): bool
    {
        return array_key_exists($status, $this->statusOptions());
    }

    /**
     * Whether an Order is in a terminal state, so no further cancel/refund
     * action applies.
     */
    private function isTerminal(Order $order): bool
    {
        return in_array($order->status, [
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_VOIDED,
            Order::STATUS_EXPIRED,
        ], true);
    }
}
