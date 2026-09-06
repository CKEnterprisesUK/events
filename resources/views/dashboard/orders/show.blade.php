@extends('layouts.dashboard')

@section('title', 'Order ' . $order->order_reference)

@push('head')
<style>
    .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1.25rem; }
    .detail__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .detail__value { font-weight: 600; color: var(--ink); }
    .money-table { width: 100%; border-collapse: collapse; }
    .money-table td { padding: 0.5rem 1.25rem; }
    .money-table td.num { text-align: right; }
    .money-table tr.total td { border-top: 1px solid var(--border); font-weight: 700; }
    .pill--paid, .pill--free_confirmed { background: #ecfdf5; color: #047857; }
    .pill--reserved { background: #eff6ff; color: #1d4ed8; }
    .pill--refunded, .pill--disputed { background: #fef2f2; color: #b91c1c; }
    .pill--cancelled, .pill--expired, .pill--voided { background: #f3f4f6; color: #6b7280; }
    .back-link { display: inline-block; margin-bottom: 0.75rem; font-size: 0.9rem; }
    .manage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; padding: 1.25rem; }
    .manage-action { display: flex; flex-direction: column; }
    .manage-action__title { margin: 0 0 0.35rem; font-size: 1rem; }
    .manage-action .input { width: 100%; }
    .manage-action .cell-dim { margin: 0 0 0.75rem; }
    .history { list-style: none; margin: 0; padding: 0.5rem 0; }
    .history__item { padding: 0.85rem 1.25rem; border-top: 1px solid var(--border); }
    .history__item:first-child { border-top: none; }
    .history__head { display: flex; align-items: baseline; gap: 0.75rem; flex-wrap: wrap; }
    .history__action { font-weight: 600; color: var(--ink); }
    .history__amount { font-weight: 600; color: #b91c1c; }
    .history__when { margin-left: auto; font-size: 0.85rem; color: var(--muted); }
    .history__meta { font-size: 0.85rem; color: var(--muted); margin-top: 0.15rem; }
    .history__reason { margin-top: 0.35rem; font-style: italic; color: var(--ink); }
</style>
@endpush

@section('content')
    @php
        $currency = $order->event?->company?->currency ?? auth()->user()->company?->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
    @endphp

    <a class="back-link panel__link" href="{{ route('dashboard.orders.index') }}">&larr; Back to orders</a>

    <div class="page-head">
        <h1>{{ $order->order_reference }}</h1>
        <div class="page-head__actions">
            @can('orders')
                @if ($canIssueTicket)
                    <a class="btn btn-outline" href="{{ route('dashboard.orders.ticket-pdf', $order) }}">Download ticket</a>
                    <form method="POST" action="{{ route('dashboard.orders.resend', $order) }}" class="inline-form"
                          onsubmit="return confirm('Resend the ticket email to {{ $order->customer_email }}?');">
                        @csrf
                        <button type="submit" class="btn btn-outline">Resend to customer</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <div class="panel">
        <div class="detail-grid">
            <div>
                <div class="detail__label">Status</div>
                <div class="detail__value"><span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></div>
            </div>
            <div>
                <div class="detail__label">Customer</div>
                <div class="detail__value">{{ $order->customer_name }}</div>
                <div class="cell-dim">{{ $order->customer_email }}</div>
            </div>
            <div>
                <div class="detail__label">Event</div>
                <div class="detail__value">
                    @if ($order->event)
                        <a class="panel__link" href="{{ route('dashboard.events.show', $order->event) }}">{{ $order->event->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="detail__label">Placed</div>
                <div class="detail__value">{{ $order->created_at?->format('j M Y, H:i') ?? '—' }}</div>
            </div>
            <div>
                <div class="detail__label">Check-in</div>
                <div class="detail__value">
                    @if ($order->scanned_at)
                        Scanned {{ $order->scanned_at->format('j M Y, H:i') }}
                    @else
                        Not yet scanned
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Payment</h2></div>
        <table class="money-table">
            <tbody>
                <tr><td>Ticket subtotal</td><td class="num">{{ $money($order->ticket_subtotal_minor) }}</td></tr>
                <tr><td>Booking fee</td><td class="num">{{ $money($order->booking_fee_minor) }}</td></tr>
                <tr class="total"><td>Order total</td><td class="num">{{ $money($order->order_total_minor) }}</td></tr>
                @if ($order->refunded_total_minor > 0)
                    <tr><td>Refunded to date</td><td class="num">&minus;{{ $money($order->refunded_total_minor) }}</td></tr>
                    <tr class="total"><td>Net retained</td><td class="num">{{ $money($order->order_total_minor - $order->refunded_total_minor) }}</td></tr>
                @endif
            </tbody>
        </table>

    </div>

    @unless ($terminal)
        @canany(['refund_order', 'cancel_order'])
            <div class="panel">
                <div class="panel__head"><h2>Manage order</h2></div>
                <div class="manage-grid">
                    @can('refund_order')
                        @if ($order->status === \App\Models\Order::STATUS_PAID && $order->refundableRemainingMinor() > 0)
                            <div class="manage-action">
                                <h3 class="manage-action__title">Partial refund</h3>
                                <p class="cell-dim">Refund part of the order. Up to {{ $money($order->refundableRemainingMinor()) }} remaining. Tickets stay valid until the full total is refunded.</p>
                                <form method="POST" action="{{ route('dashboard.orders.partial-refund', $order) }}"
                                      onsubmit="return confirm('Issue a partial Stripe refund for this amount?');">
                                    @csrf
                                    <label for="partial-refund-amount" class="detail__label">Amount ({{ $currency }})</label>
                                    <input type="number" id="partial-refund-amount" name="amount" class="input"
                                           step="0.01" min="0.01" max="{{ number_format($order->refundableRemainingMinor() / 100, 2, '.', '') }}"
                                           placeholder="0.00" required>
                                    <label for="partial-refund-reason" class="detail__label" style="margin-top: 0.5rem;">Reason (optional)</label>
                                    <input type="text" id="partial-refund-reason" name="reason" class="input"
                                           maxlength="500" placeholder="e.g. Customer requested one ticket refunded">
                                    <button type="submit" class="btn btn-outline" style="margin-top: 0.6rem;">Issue partial refund</button>
                                </form>
                                @error('amount')
                                    <p class="status status--error" style="margin-top: 0.5rem;">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="manage-action">
                                <h3 class="manage-action__title">Full refund</h3>
                                <p class="cell-dim">Refund the outstanding {{ $money($order->refundableRemainingMinor()) }}, void the tickets and release capacity.</p>
                                <form method="POST" action="{{ route('dashboard.orders.refund', $order) }}"
                                      onsubmit="return confirm('Refund this order in full? This issues a Stripe refund and voids the tickets.');">
                                    @csrf
                                    <label for="refund-reason" class="detail__label">Reason (optional)</label>
                                    <input type="text" id="refund-reason" name="reason" class="input"
                                           maxlength="500" placeholder="e.g. Event cancelled">
                                    <button type="submit" class="btn btn-danger" style="margin-top: 0.6rem;">Refund in full</button>
                                </form>
                            </div>
                        @endif
                    @endcan

                    @can('cancel_order')
                        <div class="manage-action">
                            <h3 class="manage-action__title">Cancel order</h3>
                            <p class="cell-dim">Void the tickets and release capacity. No money moves — refund a paid order instead if the customer paid.</p>
                            <form method="POST" action="{{ route('dashboard.orders.cancel', $order) }}"
                                  onsubmit="return confirm('Cancel this order? This voids the tickets and releases capacity.');">
                                @csrf
                                <label for="cancel-reason" class="detail__label">Reason (optional)</label>
                                <input type="text" id="cancel-reason" name="reason" class="input"
                                       maxlength="500" placeholder="e.g. Duplicate booking">
                                <button type="submit" class="btn btn-outline" style="margin-top: 0.6rem;">Cancel order</button>
                            </form>
                        </div>
                    @endcan
                </div>
            </div>
        @endcanany
    @endunless

    <div class="panel">
        <div class="panel__head"><h2>Tickets ({{ $order->tickets->count() }})</h2></div>
        @if ($order->tickets->isEmpty())
            <div class="empty"><p>No tickets on this order.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Ticket type</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->tickets as $ticket)
                        <tr>
                            <td>{{ $ticket->ticketType?->name ?? '—' }}</td>
                            <td><span class="pill {{ $ticket->status === \App\Models\Ticket::STATUS_VALID ? 'pill--live' : 'pill--voided' }}">{{ ucfirst($ticket->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Consent records</h2></div>
        @if ($order->consents->isEmpty())
            <div class="empty"><p>No consent records captured for this order.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Consent</th>
                        <th scope="col">Given</th>
                        <th scope="col">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->consents as $consent)
                        <tr>
                            <td>{{ ucfirst(str_replace('_', ' ', $consent->consent_key)) }}</td>
                            <td>{{ $consent->accepted ? 'Yes' : 'No' }}</td>
                            <td>{{ $consent->captured_at?->format('j M Y, H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <div class="panel__head"><h2>History</h2></div>
        @if ($history->isEmpty())
            <div class="empty"><p>No recorded actions on this order yet.</p></div>
        @else
            <ul class="history">
                @foreach ($history as $entry)
                    @php
                        $ctx = $entry->context ?? [];
                        $amountMinor = $ctx['amount_minor'] ?? null;
                        $reason = $ctx['reason'] ?? null;
                    @endphp
                    <li class="history__item">
                        <div class="history__head">
                            <span class="history__action">{{ $entry->actionLabel() }}</span>
                            @if (! is_null($amountMinor))
                                <span class="history__amount">{{ $money((int) $amountMinor) }}</span>
                            @endif
                            <span class="history__when">{{ $entry->created_at?->format('j M Y, H:i') ?? '—' }}</span>
                        </div>
                        <div class="history__meta">
                            {{ $entry->actor_label ?? 'System' }}
                            @if ($entry->is_impersonated)
                                <span class="pill pill--reserved" style="margin-left: 0.4rem;">impersonated</span>
                            @endif
                        </div>
                        @if ($reason)
                            <div class="history__reason">&ldquo;{{ $reason }}&rdquo;</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
