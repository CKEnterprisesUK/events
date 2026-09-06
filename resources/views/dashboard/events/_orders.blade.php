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
