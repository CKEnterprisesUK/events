<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCapacityException;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Jobs\SendTicketEmailJob;
use App\Services\AuditLogger;
use App\Services\CompTicketService;
use App\Services\OrderCancellationService;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use App\Services\TicketPdfService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        private readonly AuditLogger $audit,
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
            'canIssueTicket' => $this->canIssueTicket($order),
        ]);
    }

    /**
     * Stream the Order's e-ticket as a downloadable A4 PDF for the box office
     * to print or hand out manually. Gated on `ACTION_MANAGE_ORDERS` and scoped
     * to the acting Company by the tenant scope (foreign Orders 404). Only a
     * confirmed (paid / free-confirmed) Order carries a valid QR, so an
     * unconfirmed or terminal Order is refused rather than issuing a ticket that
     * would not scan.
     */
    public function downloadTicket(Order $order, TicketPdfService $tickets): Response
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        abort_unless($this->canIssueTicket($order), 403, 'This order has no issuable ticket.');

        $order->loadMissing('event');

        return $tickets->make($order)->download($tickets->filename($order));
    }

    /**
     * Re-send the branded ticket email to the customer on the Order's recorded
     * address. Reuses the same fulfilment path/QR as the original send — the QR
     * payload is a pure function of the Order reference, so the resent ticket
     * scans identically. Gated on `ACTION_MANAGE_ORDERS`; only a confirmed Order
     * (which has a QR and a fulfilment) can be resent.
     */
    public function resend(Order $order, QrService $qr): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        abort_unless($this->canIssueTicket($order), 403, 'This order has no ticket to resend.');

        SendTicketEmailJob::dispatch($order->getKey(), $qr->payloadFor($order));

        // Record the manual re-send for "I never received my ticket" disputes.
        // The recipient email is on the Order itself, so it is not duplicated
        // into the PII-minimised context.
        $this->audit->record(
            action: AuditLog::ORDER_TICKET_RESENT,
            auditable: $order,
            summary: 'Re-sent the ticket email for order '.$order->order_reference,
            context: ['order_reference' => $order->order_reference],
        );

        return back()->with('status', 'Ticket email queued to '.$order->customer_email.'.');
    }

    /**
     * Whether the Order can have a ticket issued (downloaded/resent): it must be
     * confirmed (paid or free-confirmed) so it carries a valid, scannable QR.
     */
    private function canIssueTicket(Order $order): bool
    {
        return $order->isConfirmed();
    }

    /**
     * Cancel an Order: void its Tickets so the QR fails at scan and return its
     * held capacity (reserved or sold) for resale. No money moves — a paid
     * Order is refunded via {@see refund()}. (Requirements 17.1, 17.3)
     */
    public function cancel(Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CANCEL_ORDER);

        // Log only when this call actually performed the transition; an
        // already-terminal Order is an idempotent no-op and records nothing.
        if ($this->cancellation->cancel($order)) {
            $this->audit->record(
                action: AuditLog::ORDER_CANCELLED,
                auditable: $order,
                summary: 'Cancelled order '.$order->order_reference,
                context: ['order_reference' => $order->order_reference],
            );
        }

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

        // Capture the refunded amount before the transition for the trail. Log
        // only when this call actually refunded (idempotent no-op logs nothing).
        $amountMinor = $order->order_total_minor;

        if ($this->cancellation->refund($order)) {
            $this->audit->record(
                action: AuditLog::ORDER_REFUNDED,
                auditable: $order,
                summary: 'Refunded order '.$order->order_reference,
                context: [
                    'order_reference' => $order->order_reference,
                    'amount_minor' => $amountMinor,
                ],
            );
        }

        return back()->with('status', 'Order refunded.');
    }

    /**
     * Partially refund an Order: issue a Stripe refund for a chosen amount (in
     * minor units) on the Company's connected account and record it against the
     * Order's cumulative refunded total. The Order stays paid and its Tickets
     * stay valid unless this refund brings the cumulative total up to the full
     * order total, in which case it converges on the terminal refund — voiding
     * the Tickets and returning capacity. Repeatable up to the order total.
     * Gated on `ACTION_REFUND_ORDER`. (Requirement 17.2)
     */
    public function partialRefund(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_REFUND_ORDER);

        // Amount is entered in major currency units (e.g. pounds) and converted
        // to integer minor units. It must be positive and cannot exceed the
        // amount still refundable on the Order.
        $remainingMinor = $order->refundableRemainingMinor();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $amountMinor = (int) round(((float) $validated['amount']) * 100);

        if ($amountMinor < 1 || $amountMinor > $remainingMinor) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount between 0.01 and the remaining refundable balance.',
            ]);
        }

        try {
            $applied = $this->cancellation->partialRefund($order, $amountMinor);
        } catch (\InvalidArgumentException $e) {
            // Balance changed under us (e.g. a concurrent refund/webhook) — the
            // amount no longer fits. Reject cleanly; no money moved.
            throw ValidationException::withMessages([
                'amount' => 'That amount can no longer be refunded on this order.',
            ]);
        }

        if ($applied) {
            // The Order may now be fully refunded (terminal) or still paid with
            // a larger cumulative refunded total — either way, log the amount.
            $fullyRefunded = $order->isFullyRefunded();

            $this->audit->record(
                action: $fullyRefunded ? AuditLog::ORDER_REFUNDED : AuditLog::ORDER_PARTIALLY_REFUNDED,
                auditable: $order,
                summary: ($fullyRefunded ? 'Refunded order ' : 'Partially refunded order ').$order->order_reference,
                context: [
                    'order_reference' => $order->order_reference,
                    'amount_minor' => $amountMinor,
                    'refunded_total_minor' => $order->refunded_total_minor,
                ],
            );

            return back()->with('status', $fullyRefunded
                ? 'Order fully refunded.'
                : 'Partial refund issued.');
        }

        return back()->with('status', 'Nothing left to refund on this order.');
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
        $quantities = $this->quantitiesFrom($data['items']);

        try {
            $order = $this->comps->issue(
                company: $company,
                event: $event,
                quantities: $quantities,
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

        // Record the comp issuance against the new (free-confirmed) Order. The
        // recipient's identity lives on the Order; context keeps only the
        // reference, the Event and the total ticket count.
        $this->audit->record(
            action: AuditLog::COMP_ISSUED,
            auditable: $order,
            summary: 'Issued '.array_sum($quantities).' complimentary ticket(s) for '.$event->name,
            context: [
                'order_reference' => $order->order_reference,
                'event_id' => (int) $event->getKey(),
                'ticket_count' => array_sum($quantities),
            ],
        );

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
