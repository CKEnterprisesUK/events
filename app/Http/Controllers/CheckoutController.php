<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\OrderQuestionAnswer;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Branding\BrandingResolver;
use App\Services\CapacityReservationService;
use App\Services\FeeCalculationService;
use App\Services\OrderFulfilmentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Public checkout under `/{company-slug}/{event-id}/checkout`.
 *
 * By the time `store()` runs, `ResolveTenant` has bound the active Company (or
 * already 404'd an unmatched/suspended slug) and the global `company_id` scope
 * constrains every lookup to that Company. The Event is resolved within the
 * Company (foreign ids 404), and an unpublished Event 404s here just as it does
 * on the public Event page, so a Customer can neither view nor purchase an
 * unpublished Event. (Requirements 1.5, 5.5)
 *
 * This slice creates the Order up to `reserved` status. It:
 *   1. validates the customer name (1–200) and email (1–254) and the line items
 *      (1–50 Ticket_Types that belong to the Event, with positive quantities),
 *      and that every required consent was accepted (Requirements 10.1, 10.2,
 *      10.3, 10.4, 22.4);
 *   2. checks each requested Ticket_Type is purchasable now — Event published
 *      and within the sale window (Requirements 5.5, 6.4, 6.5);
 *   3. gates paid checkout on a connected Stripe account with charges enabled;
 *      free-only orders are always permitted (Requirements 10.8, 11.3);
 *   4. reserves capacity atomically for a 900s window via the
 *      {@see CapacityReservationService}; an over-request is rejected cleanly
 *      with no Order created (Requirements 10.6, 10.7);
 *   5. snapshots the money and fee mode via {@see FeeCalculationService}
 *      (Requirements 10.10, 12.x, 13.4, 13.5, 13.8);
 *   6. creates the Order in `reserved` status with a Platform-unique
 *      Order_Reference, one Ticket per purchased/claimed ticket, and the
 *      captured consents (Requirements 10.5, 10.13).
 *
 * From the reserved Order it then hands off to payment (task 15):
 *   - A FREE-only Order (`order_total_minor == 0`) skips Stripe entirely and is
 *     confirmed immediately as `free_confirmed`, with no charge; the Customer
 *     is sent to the success return page (Requirements 10.9, 12.5).
 *   - A PAID Order creates a Stripe Checkout Session as a DIRECT CHARGE on the
 *     Company's connected account, with `application_fee_amount` set to the
 *     Order's Application_Fee and the charge amount equal to the Order_Total in
 *     the Company currency; the session id is persisted on the Order and the
 *     Customer is redirected to hosted Checkout (Requirements 10.10, 10.11,
 *     12.1). The Order is only marked paid by the `checkout.session.completed`
 *     webhook (task 16) — never by the browser return, which is display-only.
 *
 * Fulfilment/QR (task 18) and the payment webhook (task 16) build on this.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private CapacityReservationService $capacity,
        private FeeCalculationService $fees,
        private StripePaymentService $stripe,
        private OrderFulfilmentService $fulfilment,
    ) {}

    /**
     * Render the dedicated checkout step. The public Event page now handles
     * discovery + ticket selection only; when the Customer has chosen their
     * quantities it POSTs them here, and this page collects the customer/booking
     * details, consents and starts payment. Splitting the flow keeps the Event
     * page focused on the tickets and gives payment its own uncluttered step.
     *
     * The posted line items are resolved against this Event's purchasable
     * Ticket_Types (the same rules {@see store()} enforces) so the summary shown
     * here is trustworthy; an empty or invalid selection sends the Customer back
     * to the Event page with a message rather than showing an empty checkout.
     */
    public function review(
        Request $request,
        string $companySlug,
        Event $event,
        TenantContext $tenantContext,
        BrandingResolver $branding,
    ): View|RedirectResponse {
        // Unpublished Events block Customer view/purchase, mirroring the public
        // Event page and store(). (Requirement 5.5)
        abort_unless($event->isPublished(), 404);

        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        // Drop zero-quantity lines the stepper submits for untouched rows, then
        // resolve the rest to purchasable Ticket_Types. An empty or unavailable
        // selection returns to the Event page rather than an empty checkout.
        $items = array_values(array_filter(
            $validated['items'],
            fn (array $item): bool => (int) $item['quantity'] > 0,
        ));

        $backToEvent = redirect()->route('event.page', [
            'companySlug' => $companySlug,
            'event' => $event->id,
        ]);

        if ($items === []) {
            return $backToEvent->with('checkout_error', 'Choose at least one ticket to continue.');
        }

        $now = Carbon::now();

        try {
            [$quantities, $ticketTypes] = $this->resolveLineItems($event, $items, $now);
        } catch (ValidationException $e) {
            return $backToEvent->withErrors($e->errors());
        }

        // Money snapshot for display only — the authoritative charge is computed
        // again in store() from the same inputs, so the two always agree.
        $subtotal = $this->subtotal($quantities, $ticketTypes);
        $fee = $this->fees->calculateForCompany($company, $subtotal);

        // Build ordered summary lines (Ticket_Type => qty + line total) for the
        // read-only order summary on the checkout page.
        $lines = [];
        foreach ($quantities as $ticketTypeId => $qty) {
            $type = $ticketTypes[$ticketTypeId];
            $lines[] = [
                'ticket_type_id' => $ticketTypeId,
                'name' => $type->name,
                'quantity' => $qty,
                'is_free' => $type->isFree(),
                'unit_price_minor' => $type->price_minor,
                'line_total_minor' => $type->price_minor * $qty,
            ];
        }

        return view('events.checkout', [
            'company' => $company,
            'event' => $event,
            'branding' => $branding->forEvent($event),
            'lines' => $lines,
            'subtotalMinor' => $subtotal,
            'feeMinor' => $fee->bookingFee,
            'totalMinor' => $fee->orderTotal,
            'feeHandlingMode' => $company->fee_handling_mode,
            // The organiser's custom questions (0–3), asked once per order below
            // the customer details. (Attendee questions feature)
            'questions' => $event->questions()->get(),
        ]);
    }

    public function store(
        Request $request,
        string $companySlug,
        Event $event,
        TenantContext $tenantContext,
    ): RedirectResponse {
        // Unpublished Events block Customer view/purchase, mirroring the public
        // Event page. (Requirement 5.5)
        abort_unless($event->isPublished(), 404);

        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        $data = $this->validated($request);

        // Resolve the requested line items to this Event's Ticket_Types and
        // confirm each is purchasable now (published + within sale window).
        // (Requirements 6.4, 6.5, 10.6)
        $now = Carbon::now();
        [$quantities, $ticketTypes] = $this->resolveLineItems($event, $data['items'], $now);

        // Required-consent gating: every consent key marked required must have
        // been accepted. No Order is created otherwise. (Requirements 10.3,
        // 10.4, 22.4)
        $consents = $this->normalizeConsents($data['consents'] ?? []);
        $this->assertRequiredConsentsAccepted($consents);

        // Resolve the organiser's custom questions and validate the submitted
        // answers against them (required-answer gating + per-type checks). The
        // result is a list of {question, answer} pairs ready to persist. No
        // Order is created if a required question is unanswered. (Attendee
        // questions feature)
        $answers = $this->resolveAnswers($event, (array) $request->input('questions', []));

        // Money snapshot for the Order, computed from the Company's effective
        // fee percent and Fee_Handling_Mode. (Requirements 10.10, 12.x, 13.x)
        $subtotal = $this->subtotal($quantities, $ticketTypes);
        $fee = $this->fees->calculateForCompany($company, $subtotal);

        // Charges-enabled gating: a paid order requires a connected Stripe
        // account with charges enabled; free-only orders are always permitted.
        // (Requirements 10.8, 11.3)
        if (! $fee->isFree() && ! $this->companyCanAcceptPaidOrders($company)) {
            throw ValidationException::withMessages([
                'payment' => 'This organiser cannot accept paid orders yet.',
            ]);
        }

        // Reserve capacity atomically for a 900s window. An over-request is
        // rejected cleanly with nothing reserved and no Order created.
        // (Requirements 10.6, 10.7)
        try {
            $reservedUntil = $this->capacity->reserve($event, $quantities);
        } catch (InsufficientCapacityException $e) {
            throw ValidationException::withMessages([
                'items' => 'Not enough tickets remain for your selection.',
            ]);
        }

        // Persist the Order + Tickets + consents atomically. If any part fails,
        // release the just-made reservation so capacity is not stranded.
        try {
            $order = DB::transaction(function () use ($event, $data, $fee, $reservedUntil, $quantities, $consents, $answers): Order {
                $order = Order::create([
                    'event_id' => $event->id,
                    'order_reference' => $this->uniqueOrderReference(),
                    'customer_name' => $data['customer_name'],
                    'customer_email' => $data['customer_email'],
                    'status' => Order::STATUS_RESERVED,
                    'reserved_until' => $reservedUntil,
                    ...$fee->toArray(),
                ]);

                // One Ticket row per purchased/claimed ticket. (Requirement 10.5)
                foreach ($quantities as $ticketTypeId => $qty) {
                    for ($i = 0; $i < $qty; $i++) {
                        Ticket::create([
                            'order_id' => $order->id,
                            'ticket_type_id' => $ticketTypeId,
                            'status' => Ticket::STATUS_VALID,
                        ]);
                    }
                }

                // Snapshot the submitted consent selections. (Requirements 10.3,
                // 10.4, 22.4)
                foreach ($consents as $key => $accepted) {
                    OrderConsent::create([
                        'order_id' => $order->id,
                        'consent_key' => $key,
                        'accepted' => $accepted,
                        'captured_at' => now(),
                    ]);
                }

                // Snapshot the custom-question answers, one row per question,
                // with the question label captured so reports survive later
                // edits/deletes of the question. (Attendee questions feature)
                foreach ($answers as $answer) {
                    /** @var EventQuestion $question */
                    $question = $answer['question'];

                    OrderQuestionAnswer::create([
                        'order_id' => $order->id,
                        'event_question_id' => $question->id,
                        'question_label' => $question->label,
                        'answer' => $answer['answer'],
                        'captured_at' => now(),
                    ]);
                }

                return $order;
            });
        } catch (\Throwable $e) {
            // Roll the held capacity back so a persistence failure does not
            // strand a reservation. (Requirement 10.7)
            $this->capacity->release($event, $quantities);

            throw $e;
        }

        // Free-only Order: no Stripe charge. Confirm immediately as
        // `free_confirmed` and send the Customer to the success return page.
        // (Requirements 10.9, 12.5, 13.7)
        if ($order->isFree()) {
            $order->status = Order::STATUS_FREE_CONFIRMED;
            $order->save();

            // A free Order is confirmed here (no Stripe), so fulfil it now:
            // commit capacity reserved → sold, generate the QR, and enqueue the
            // ticket email on the DB queue. Fulfilment is idempotent.
            // (Requirements 10.9, 14.1, 14.3)
            $this->fulfilment->fulfil($order);

            return redirect()->route('checkout.success', [
                'companySlug' => $companySlug,
                'event' => $event->id,
                'order' => $order->order_reference,
            ]);
        }

        // Paid Order: create a Stripe Checkout Session as a DIRECT CHARGE on the
        // Company's connected account, with `application_fee_amount` set to the
        // Order's Application_Fee and the charge amount equal to the Order_Total,
        // in the Company currency. Persist the session id and redirect the
        // Customer to hosted Checkout. The Order is only marked paid by the
        // webhook (task 16) — the return pages are display-only. (Requirements
        // 10.10, 10.11, 12.1, 12.5)
        $session = $this->stripe->createCheckoutSession(
            connectedAccountId: (string) $company->stripe_account_id,
            currency: $company->currency,
            amountMinor: $order->order_total_minor,
            applicationFeeMinor: $order->application_fee_minor,
            successUrl: route('checkout.success', [
                'companySlug' => $companySlug,
                'event' => $event->id,
                'order' => $order->order_reference,
            ]),
            cancelUrl: route('checkout.cancel', [
                'companySlug' => $companySlug,
                'event' => $event->id,
                'order' => $order->order_reference,
            ]),
            metadata: ['order_reference' => $order->order_reference],
            // Pre-fill the email the Customer just entered so Stripe's hosted
            // page doesn't ask them for it a second time.
            customerEmail: $order->customer_email,
        );

        $order->stripe_session_id = $session->id;
        $order->save();

        return redirect()->away($session->url);
    }

    /**
     * Validate the checkout payload. Customer name 1–200 and email 1–254 must
     * be present and non-blank (Requirements 10.1, 10.2). 1–50 line items
     * (Requirement 10.1). Consents is an optional map of key => bool.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_name' => ['required', 'string', 'min:1', 'max:200'],
            'customer_email' => ['required', 'string', 'min:1', 'max:254', 'email'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'consents' => ['nullable', 'array'],
        ]);
    }

    /**
     * Resolve and validate the Customer's answers to the Event's custom
     * questions against the questions actually configured for the Event.
     *
     * For each question:
     *   - a required question must have a non-empty answer, else a validation
     *     error keyed `questions.{id}` is raised and no Order is created;
     *   - a `number` question's answer must be numeric;
     *   - a `select` question's answer must be one of the configured choices.
     *
     * Submitted answers for unknown question ids (not belonging to this Event)
     * are ignored. The result is a list of {question, answer} pairs to persist;
     * a question left blank (and optional) is skipped rather than stored empty.
     *
     * @param  array<int|string, mixed>  $submitted  The raw `questions[id] => value` map.
     * @return array<int, array{question: EventQuestion, answer: string}>
     */
    private function resolveAnswers(Event $event, array $submitted): array
    {
        $questions = $event->questions()->get();

        if ($questions->isEmpty()) {
            return [];
        }

        $errors = [];
        $resolved = [];

        foreach ($questions as $question) {
            $raw = $submitted[$question->id] ?? null;
            $value = is_string($raw) ? trim($raw) : (is_scalar($raw) ? (string) $raw : '');

            if ($value === '') {
                if ($question->required) {
                    $errors["questions.{$question->id}"] = __('Please answer: :question', ['question' => $question->label]);
                }

                // Optional + unanswered: nothing to store.
                continue;
            }

            if ($question->isNumber() && ! is_numeric($value)) {
                $errors["questions.{$question->id}"] = __('Please enter a number for: :question', ['question' => $question->label]);

                continue;
            }

            if ($question->isSelect() && ! in_array($value, $question->choices(), true)) {
                $errors["questions.{$question->id}"] = __('Please choose one of the options for: :question', ['question' => $question->label]);

                continue;
            }

            $resolved[] = [
                'question' => $question,
                'answer' => $value,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * Resolve the submitted line items to a quantities map (Ticket_Type id =>
     * quantity) and the loaded Ticket_Types, rejecting any Ticket_Type that
     * does not belong to the Event or that is not purchasable now.
     *
     * @param  array<int, array{ticket_type_id:int, quantity:int}>  $items
     * @return array{0: array<int, int>, 1: array<int, TicketType>}
     */
    private function resolveLineItems(Event $event, array $items, Carbon $now): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $id = (int) $item['ticket_type_id'];
            $qty = (int) $item['quantity'];

            // Sum duplicate lines for the same type.
            $quantities[$id] = ($quantities[$id] ?? 0) + $qty;
        }

        $ticketTypes = $event->ticketTypes()
            ->whereIn('id', array_keys($quantities))
            ->get()
            ->keyBy('id');

        foreach (array_keys($quantities) as $id) {
            // Reject line items referencing a Ticket_Type outside this Event
            // (or another Company — those never match the scoped query).
            if (! $ticketTypes->has($id)) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected ticket types are not available for this event.',
                ]);
            }

            /** @var TicketType $type */
            $type = $ticketTypes->get($id);

            // Event published + within the sale window. (Requirements 5.5, 6.4, 6.5)
            if (! $type->isPurchasableAt($now)) {
                throw ValidationException::withMessages([
                    'items' => "The ticket type \"{$type->name}\" is not on sale.",
                ]);
            }
        }

        return [$quantities, $ticketTypes->all()];
    }

    /**
     * The Ticket_Subtotal in integer minor currency units: sum of each
     * requested quantity × the Ticket_Type's `price_minor`.
     *
     * @param  array<int, int>  $quantities
     * @param  array<int, TicketType>  $ticketTypes
     */
    private function subtotal(array $quantities, array $ticketTypes): int
    {
        $subtotal = 0;

        foreach ($quantities as $ticketTypeId => $qty) {
            $subtotal += $ticketTypes[$ticketTypeId]->price_minor * $qty;
        }

        return $subtotal;
    }

    /**
     * Normalize the submitted consents map to key => bool.
     *
     * @param  array<string, mixed>  $consents
     * @return array<string, bool>
     */
    private function normalizeConsents(array $consents): array
    {
        $normalized = [];

        foreach ($consents as $key => $value) {
            $normalized[(string) $key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    /**
     * Reject the checkout if any required consent was not accepted. No Order is
     * created; the error names the missing consent. (Requirements 10.4, 22.4)
     *
     * @param  array<string, bool>  $consents
     */
    private function assertRequiredConsentsAccepted(array $consents): void
    {
        foreach ($this->requiredConsentKeys() as $key) {
            if (($consents[$key] ?? false) !== true) {
                throw ValidationException::withMessages([
                    "consents.{$key}" => "The {$key} consent is required.",
                ]);
            }
        }
    }

    /**
     * The consent keys that must be accepted at checkout. `terms` and `privacy`
     * are always required; `marketing` (when submitted) is optional.
     *
     * @return list<string>
     */
    private function requiredConsentKeys(): array
    {
        return ['terms', 'privacy'];
    }

    /**
     * Whether the Company may accept paid orders: a connected Stripe account
     * with charges enabled. (Requirements 10.8, 11.3)
     */
    private function companyCanAcceptPaidOrders(Company $company): bool
    {
        return $company->stripe_account_id !== null
            && (bool) $company->stripe_charges_enabled;
    }

    /**
     * Generate a Platform-unique Order_Reference. Uniqueness is also enforced
     * by the UNIQUE index on `orders.order_reference`; this loop makes a
     * collision vanishingly unlikely before the insert. (Requirement 10.13)
     */
    private function uniqueOrderReference(): string
    {
        do {
            $reference = strtoupper(Str::random(12));
        } while (Order::withoutGlobalScopes()->where('order_reference', $reference)->exists());

        return $reference;
    }
}
