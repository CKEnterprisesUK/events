{{-- Recent orders (read-only list) -----------------------------------------

     The last 25 orders for this event, newest first. Each row links through to
     the full order detail screen (dashboard.orders.show), which is where cancel
     and refund now live. The whole row is clickable via the .row-nav/.row-link
     stretched-anchor pattern; keyboard users focus the reference link. --}}
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
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recentOrders as $order)
                    <tr class="row-nav">
                        <td>
                            <a class="cell-strong row-link" href="{{ route('dashboard.orders.show', $order) }}">{{ $order->order_reference }}</a>
                        </td>
                        <td>
                            {{ $order->customer_name }}
                            <span class="cell-dim">{{ $order->customer_email }}</span>
                        </td>
                        <td><span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></td>
                        <td class="num">{{ number_format($order->order_total_minor / 100, 2) }}</td>
                        <td class="num">
                            <a class="panel__link row-action" href="{{ route('dashboard.orders.show', $order) }}">View</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
