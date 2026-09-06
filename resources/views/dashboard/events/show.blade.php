@extends('layouts.dashboard')

@section('title', $event->name)

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $event->name }}</h1>
            <p class="muted" style="margin:0;">
                <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                    {{ $event->isPublished() ? 'Published' : 'Draft' }}
                </span>
            </p>
        </div>
        <div class="page-head__actions">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.ticket-types.index', $event) }}">Ticket types</a>
            @can('settings')
                <a class="btn btn-outline btn-sm" href="{{ route('dashboard.branding.event.edit', $event) }}">Branding</a>
            @endcan
            @unless ($event->isPublished())
                <form method="POST" action="{{ route('dashboard.events.publish', $event) }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-sm">Publish</button>
                </form>
            @else
                <form method="POST" action="{{ route('dashboard.events.unpublish', $event) }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm">Unpublish</button>
                </form>
            @endunless
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    {{-- Event details / edit --------------------------------------------- --}}
    <div class="panel form-panel">
        <div class="panel__head">
            <h2>Event details</h2>
        </div>
        <form method="POST" action="{{ route('dashboard.events.update', $event) }}" class="stack">
            @csrf
            @method('PUT')
            @include('dashboard.events._form', ['event' => $event])
            <div class="form-actions">
                <button type="submit" class="btn">Save changes</button>
            </div>
        </form>
    </div>

    {{-- Complimentary tickets --------------------------------------------- --}}
    @can('issue_comp')
        <div class="panel form-panel">
            <div class="panel__head"><h2>Issue complimentary tickets</h2></div>
            @if ($ticketTypes->isEmpty())
                <div class="empty">
                    <p>Add a ticket type before issuing complimentary tickets.</p>
                    <a class="btn btn-sm" href="{{ route('dashboard.events.ticket-types.index', $event) }}">Add ticket type</a>
                </div>
            @else
                <form method="POST" action="{{ route('dashboard.events.comp', $event) }}" class="stack">
                    @csrf
                    <div class="field-row">
                        <div class="field">
                            <label for="recipient_name">Recipient name</label>
                            <input id="recipient_name" type="text" name="recipient_name" required
                                   value="{{ old('recipient_name') }}">
                            @error('recipient_name') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="recipient_email">Recipient email</label>
                            <input id="recipient_email" type="email" name="recipient_email" required
                                   value="{{ old('recipient_email') }}">
                            @error('recipient_email') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <label class="field-label">Quantities</label>
                    <table class="data-table">
                        <thead>
                            <tr><th>Ticket type</th><th class="num">Available</th><th style="width:120px;">Quantity</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($ticketTypes as $i => $type)
                                <tr>
                                    <td>
                                        <span class="cell-strong">{{ $type->name }}</span>
                                        <input type="hidden" name="items[{{ $i }}][ticket_type_id]" value="{{ $type->id }}">
                                    </td>
                                    <td class="num">{{ $type->availableQuantity() }}</td>
                                    <td>
                                        <input type="number" name="items[{{ $i }}][quantity]" min="0" value="0"
                                               max="{{ max(0, $type->availableQuantity()) }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="hint">Set a quantity of 0 for ticket types you don't want to include.</p>
                    @error('items') <p class="error">{{ $message }}</p> @enderror

                    <div class="form-actions">
                        <button type="submit" class="btn">Issue tickets</button>
                    </div>
                </form>
            @endif
        </div>
    @endcan

    {{-- Recent orders (cancel / refund) ---------------------------------- --}}
    @canany(['cancel_order', 'refund_order'])
        <div class="panel">
            <div class="panel__head"><h2>Recent orders</h2></div>
            @if ($recentOrders->isEmpty())
                <div class="empty"><p>No orders yet.</p></div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th class="num">Total</th>
                            <th class="num">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentOrders as $order)
                            @php
                                $terminal = in_array($order->status, [
                                    \App\Models\Order::STATUS_CANCELLED,
                                    \App\Models\Order::STATUS_REFUNDED,
                                    \App\Models\Order::STATUS_VOIDED,
                                    \App\Models\Order::STATUS_EXPIRED,
                                ], true);
                            @endphp
                            <tr>
                                <td><span class="cell-strong">{{ $order->order_reference }}</span></td>
                                <td>
                                    {{ $order->customer_name }}
                                    <span class="cell-dim">{{ $order->customer_email }}</span>
                                </td>
                                <td><span class="pill pill--draft">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></td>
                                <td class="num">{{ number_format($order->order_total_minor / 100, 2) }}</td>
                                <td class="num">
                                    @if ($terminal)
                                        <span class="cell-dim">—</span>
                                    @else
                                        <div class="row-actions">
                                            @can('refund_order')
                                                @if ($order->status === \App\Models\Order::STATUS_PAID)
                                                    <form method="POST" action="{{ route('dashboard.orders.refund', $order) }}" class="inline-form"
                                                          onsubmit="return confirm('Refund this order? This issues a Stripe refund and voids the tickets.');">
                                                        @csrf
                                                        <button type="submit" class="btn btn-danger btn-sm">Refund</button>
                                                    </form>
                                                @endif
                                            @endcan
                                            @can('cancel_order')
                                                <form method="POST" action="{{ route('dashboard.orders.cancel', $order) }}" class="inline-form"
                                                      onsubmit="return confirm('Cancel this order? This voids the tickets and releases capacity.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-outline btn-sm">Cancel</button>
                                                </form>
                                            @endcan
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endcanany
@endsection
