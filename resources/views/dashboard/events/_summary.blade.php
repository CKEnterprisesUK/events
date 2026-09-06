{{--
    Compact inline per-event stats summary (Requirement 5.1).

    Expects:
      - $event  the Event in scope (already ACTION_MANAGE_EVENTS-gated by the
                enclosing show page).
      - $report an App\Services\Reporting\EventReport with public readonly
                ->confirmedOrders, ->ticketsSold, ->grossRevenueMinor,
                ->netToCompanyMinor, ->capacity, and ->utilisation().

    Money is stored in integer minor-currency units; render as major units with
    number_format(... / 100, 2), matching show.blade.php / reports/index.

    The figures are visible to any event manager who can reach this page. Only
    the "View full report" link is gated on the reporting ability (view_reports),
    since the report page itself is gated on ACTION_VIEW_REPORTS.
--}}
<div class="panel">
    <div class="panel__head">
        <h2>At a glance</h2>
    </div>

    <table class="data-table">
        <tbody>
            <tr>
                <th scope="row"><span class="muted">Tickets sold</span></th>
                <td class="num cell-strong">{{ $report->ticketsSold }}</td>
            </tr>
            <tr>
                <th scope="row"><span class="muted">Gross revenue</span></th>
                <td class="num cell-strong">{{ number_format($report->grossRevenueMinor / 100, 2) }}</td>
            </tr>
            <tr>
                <th scope="row"><span class="muted">Net to company</span></th>
                <td class="num cell-strong">{{ number_format($report->netToCompanyMinor / 100, 2) }}</td>
            </tr>
            <tr>
                <th scope="row"><span class="muted">Capacity utilisation</span></th>
                <td class="num cell-strong">
                    @if ($report->utilisation() === 'unlimited' || $report->capacity === null)
                        Unlimited
                    @else
                        {{ $report->utilisation() }}%
                    @endif
                </td>
            </tr>
            <tr>
                <th scope="row"><span class="muted">Confirmed orders</span></th>
                <td class="num cell-strong">{{ $report->confirmedOrders }}</td>
            </tr>
        </tbody>
    </table>

    @can('view_reports')
        <div class="panel__foot">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.report', $event) }}">View full report</a>
        </div>
    @endcan
</div>
