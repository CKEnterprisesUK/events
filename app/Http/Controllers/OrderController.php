<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCapacityException;
use App\Jobs\SendRefundEmailJob;
use App\Jobs\SendTicketEmailJob;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\CompTicketService;
use App\Services\OrderCancellationService;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use App\Services\SimpleXlsxWriter;
use App\Services\TenantContext;
use App\Services\TicketPdfService;
use App\Support\PlatformBrand;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $filters = $this->filtersFrom($request);

        $orders = $this->filteredOrders($filters)
            ->with('event')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.orders.index', [
            'orders' => $orders,
            'search' => $filters['search'],
            'status' => $filters['status'],
            'statuses' => $this->statusOptions(),
            'events' => $this->exportableEvents(),
            'eventId' => $filters['event_id'],
            'from' => $filters['from']?->toDateString(),
            'to' => $filters['to']?->toDateString(),
        ]);
    }

    /**
     * Stream the filtered Orders as a CSV or Excel workbook so an operator can
     * take the list into a spreadsheet. The export honours exactly the same
     * filters as the on-screen list — free-text search, status, a chosen Event,
     * and an inclusive created-at date range — so "what you see is what you
     * export". Gated on `ACTION_MANAGE_ORDERS` and scoped to the acting Company
     * by the tenant global scope, so a Company can only ever export its own
     * Orders. Read-only: it performs no mutation.
     *
     * The `format` query param selects the output: `xlsx` returns a real Excel
     * workbook (numeric money cells the spreadsheet can total); anything else
     * (the default) streams a CSV. Both carry one row per Order with its
     * reference, customer, event, placed-at timestamp, status and the full
     * money breakdown in major currency units.
     */
    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        $filters = $this->filtersFrom($request);

        // Oldest-first for an export so the file reads as a chronological
        // ledger, independent of the newest-first on-screen ordering.
        $orders = $this->filteredOrders($filters)
            ->with('event')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $filename = 'orders-'.now()->format('Y-m-d');

        return $request->query('format') === 'xlsx'
            ? $this->exportExcel($orders, $filename.'.xlsx')
            : $this->exportCsv($orders, $filename.'.csv');
    }

    /**
     * The column headers shared by the CSV and Excel exports.
     *
     * @return list<string>
     */
    private function exportHeader(): array
    {
        return [
            'Reference', 'Customer name', 'Email', 'Event', 'Placed at', 'Status',
            'Tickets', 'Ticket subtotal', 'Booking fee', 'Platform fee',
            'Total', 'Refunded', 'Stripe fee',
        ];
    }

    /**
     * One export row for an Order, money rendered in major units. The $money
     * callback shapes each monetary cell: a fixed-precision string for the CSV,
     * or a numeric major-unit value for the Excel workbook.
     *
     * @param  callable(int):(string|float)  $money
     * @return list<string|int|float>
     */
    private function exportRow(Order $order, callable $money): array
    {
        return [
            $order->order_reference,
            $order->customer_name,
            $order->customer_email,
            $order->event?->name ?? '—',
            optional($order->created_at)->format('Y-m-d H:i') ?? '',
            ucfirst(str_replace('_', ' ', $order->status)),
            (int) $order->tickets_count,
            $money((int) $order->ticket_subtotal_minor),
            $money((int) $order->booking_fee_minor),
            $money((int) $order->application_fee_minor),
            $money((int) $order->order_total_minor),
            $money((int) $order->refunded_total_minor),
            $money((int) ($order->stripe_fee_minor ?? 0)),
        ];
    }

    /**
     * Stream the Orders as a CSV. Money columns are written as fixed-precision
     * major-unit strings (e.g. 125.00) so they stay numeric in a spreadsheet,
     * with no currency symbol. Uses the same streamDownload + fputcsv shape as
     * the sales and attendance exports.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function exportCsv($orders, string $filename): StreamedResponse
    {
        $money = fn (int $minor): string => number_format($minor / 100, 2, '.', '');

        return response()->streamDownload(function () use ($orders, $money): void {
            $out = fopen('php://output', 'wb');

            fputcsv($out, $this->exportHeader());

            foreach ($orders as $order) {
                fputcsv($out, $this->exportRow($order, $money));
            }

            // Platform attribution footer.
            fputcsv($out, []);
            fputcsv($out, [PlatformBrand::line()]);

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Build a real `.xlsx` workbook of the Orders. Money columns are real
     * numbers in major units so finance can total/pivot them natively, unlike
     * the CSV. Falls back to a CSV when the Zip extension is unavailable rather
     * than emit a corrupt file.
     *
     * @param  Collection<int, Order>  $orders
     */
    private function exportExcel($orders, string $filename): Response|StreamedResponse
    {
        $money = fn (int $minor): float => round($minor / 100, 2);

        $writer = new SimpleXlsxWriter('Orders');
        $writer->addRow($this->exportHeader());

        foreach ($orders as $order) {
            $writer->addRow($this->exportRow($order, $money));
        }

        // Platform attribution footer.
        $writer->addBlankRow();
        $writer->addRow([PlatformBrand::line()]);

        try {
            $bytes = $writer->build();
        } catch (\RuntimeException) {
            // No Zip extension — degrade gracefully to a CSV with the same data.
            return $this->exportCsv($orders, Str::replaceLast('.xlsx', '.csv', $filename));
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename='.$filename,
        ]);
    }

    /**
     * Parse the shared Orders filters from the request: a free-text search
     * (reference / name / email), a status, an optional Event id and an
     * inclusive created-at date range. A blank or unknown value drops that
     * filter rather than erroring. A reversed range is swapped so it is always
     * well-ordered.
     *
     * @return array{search:string, status:string, event_id:?int, from:?Carbon, to:?Carbon}
     */
    private function filtersFrom(Request $request): array
    {
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $eventId = $request->query('event');
        $eventId = is_numeric($eventId) ? (int) $eventId : null;

        return [
            'search' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'event_id' => $eventId,
            'from' => $from?->startOfDay(),
            'to' => $to?->endOfDay(),
        ];
    }

    /**
     * The shared, tenant-scoped Orders query with the active filters applied,
     * plus a count of each Order's tickets for the export. Unordered so callers
     * choose their own sort (newest-first on screen, oldest-first for exports).
     *
     * @param  array{search:string, status:string, event_id:?int, from:?Carbon, to:?Carbon}  $filters
     * @return Builder<Order>
     */
    private function filteredOrders(array $filters)
    {
        return Order::query()
            ->withCount('tickets')
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $like = '%'.$filters['search'].'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('order_reference', 'like', $like)
                        ->orWhere('customer_name', 'like', $like)
                        ->orWhere('customer_email', 'like', $like);
                });
            })
            ->when($this->isKnownStatus($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['event_id'] !== null, fn ($query) => $query->where('event_id', $filters['event_id']))
            ->when($filters['from'] !== null, fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn ($query) => $query->where('created_at', '<=', $filters['to']));
    }

    /**
     * The Company's Events for the export/filter dropdown, newest first and
     * keyed id => name. Tenant-scoped via the global company scope.
     *
     * @return Collection<int, string>
     */
    private function exportableEvents()
    {
        return Event::query()
            ->orderByDesc('id')
            ->pluck('name', 'id');
    }

    /**
     * Parse a `Y-m-d` query value into a Carbon date, or null when absent or
     * malformed (so a bad param simply widens the range rather than erroring).
     * Mirrors ReportController's parser so every surface filters consistently.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }
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
            'history' => $this->historyFor($order),
        ]);
    }

    /**
     * The audit trail for this Order, newest first: cancels, (partial) refunds,
     * disputes, ticket re-sends and any other action recorded against it —
     * including system/webhook rows (a `charge.refunded` or dispute webhook) so
     * the operator sees the full "what happened and when" on one screen, with
     * the reason and amount each action recorded. Scoped to the Order via the
     * polymorphic auditable link; AuditLog is not tenant-scoped, so it is
     * constrained here to this Order's Company as a defence-in-depth check on
     * top of route-model binding already scoping the Order to the tenant.
     *
     * @return Collection<int, AuditLog>
     */
    private function historyFor(Order $order)
    {
        return AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->getKey())
            ->where('company_id', $order->company_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
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
     * Re-send the refund confirmation email to the customer for an Order that
     * has been (fully or partially) refunded. Reuses the same branded email as
     * the original refund send, re-stating the amount refunded to date and the
     * up-to-7-working-days bank timing. Gated on `ACTION_REFUND_ORDER`; only an
     * Order that carries a refund (`refunded_total_minor > 0`) can be resent so
     * we never email a refund confirmation for an order that was not refunded.
     */
    public function resendRefund(Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_REFUND_ORDER);

        abort_unless(
            (int) $order->refunded_total_minor > 0,
            403,
            'This order has no refund to confirm.',
        );

        // Re-state the cumulative refunded total, and whether the order is now
        // fully refunded, so a resend reflects the order's current refund state
        // (e.g. after one or more partial refunds).
        SendRefundEmailJob::dispatch(
            $order->getKey(),
            (int) $order->refunded_total_minor,
            $order->isFullyRefunded(),
        );

        $this->audit->record(
            action: AuditLog::ORDER_REFUND_EMAIL_RESENT,
            auditable: $order,
            summary: 'Re-sent the refund confirmation email for order '.$order->order_reference,
            context: [
                'order_reference' => $order->order_reference,
                'refunded_total_minor' => (int) $order->refunded_total_minor,
            ],
        );

        return back()->with('status', 'Refund confirmation email queued to '.$order->customer_email.'.');
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
     * Validate and normalise the optional operator "reason" that accompanies a
     * cancel/refund action. Free text up to 500 chars; blank is treated as no
     * reason. Recorded in the audit trail so the "why" is visible on the Order.
     */
    private function validatedReason(Request $request): ?string
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->normaliseReason($validated['reason'] ?? null);
    }

    /**
     * Trim a submitted reason to a non-empty string, or null when absent/blank.
     */
    private function normaliseReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }

    /**
     * Merge an optional reason into an audit context payload under the `reason`
     * key, omitting it entirely when none was given so the trail stays clean.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function contextWithReason(array $context, ?string $reason): array
    {
        if ($reason !== null) {
            $context['reason'] = $reason;
        }

        return $context;
    }

    /**
     * Cancel an Order: void its Tickets so the QR fails at scan and return its
     * held capacity (reserved or sold) for resale. No money moves — a paid
     * Order is refunded via {@see refund()}. (Requirements 17.1, 17.3)
     */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CANCEL_ORDER);

        $reason = $this->validatedReason($request);

        // Log only when this call actually performed the transition; an
        // already-terminal Order is an idempotent no-op and records nothing.
        if ($this->cancellation->cancel($order)) {
            $this->audit->record(
                action: AuditLog::ORDER_CANCELLED,
                auditable: $order,
                summary: 'Cancelled order '.$order->order_reference,
                context: $this->contextWithReason(['order_reference' => $order->order_reference], $reason),
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
    public function refund(Request $request, Order $order): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_REFUND_ORDER);

        $reason = $this->validatedReason($request);

        // Capture the amount actually refunded (the outstanding balance) before
        // the transition for the trail. Log only when this call actually
        // refunded (idempotent no-op logs nothing).
        $amountMinor = $order->refundableRemainingMinor();

        if ($this->cancellation->refund($order)) {
            $this->audit->record(
                action: AuditLog::ORDER_REFUNDED,
                auditable: $order,
                summary: 'Refunded order '.$order->order_reference,
                context: $this->contextWithReason([
                    'order_reference' => $order->order_reference,
                    'amount_minor' => $amountMinor,
                ], $reason),
            );

            // Email the customer confirming the refund amount and the up-to-7-
            // working-days bank timing. A full refund is always fully refunded.
            SendRefundEmailJob::dispatch($order->getKey(), $amountMinor, true);
        }

        return back()->with('status', 'Order refunded. A confirmation email has been sent to the customer.');
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
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $this->normaliseReason($validated['reason'] ?? null);

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
                context: $this->contextWithReason([
                    'order_reference' => $order->order_reference,
                    'amount_minor' => $amountMinor,
                    'refunded_total_minor' => $order->refunded_total_minor,
                ], $reason),
            );

            // Email the customer confirming this refund amount and the up-to-7-
            // working-days bank timing. Carries whether the order is now fully
            // refunded (tickets voided) vs a standing partial refund.
            SendRefundEmailJob::dispatch($order->getKey(), $amountMinor, $fullyRefunded);

            return back()->with('status', $fullyRefunded
                ? 'Order fully refunded. A confirmation email has been sent to the customer.'
                : 'Partial refund issued. A confirmation email has been sent to the customer.');
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
