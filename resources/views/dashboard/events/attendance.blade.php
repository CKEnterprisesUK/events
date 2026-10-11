@extends('layouts.event')

@section('active_section', 'attendance')

@section('section')
    @php
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1) . '%';
        $fmtTime = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('j M Y, H:i') : '—';
        $fmtHour = fn (string $hour) => \Illuminate\Support\Carbon::parse($hour)->format('j M · H:00');
        // The busiest single hour, to scale the timeline bars.
        $peak = collect($attendance->timeline)->max('tickets') ?: 0;
    @endphp

    <div class="panel__head panel__head--bare">
        <div>
            <h2>Attendance &amp; check-in</h2>
            <p class="hint">
                Who actually arrived at the door, from the ticket scans. Based on
                {{ number_format($attendance->confirmedOrders) }}
                confirmed {{ \Illuminate\Support\Str::plural('order', $attendance->confirmedOrders) }}.
            </p>
        </div>
        @if ($attendance->confirmedOrders > 0)
            @php $exportParams = array_merge(['event' => $event], array_filter(['from' => $range->fromDate(), 'to' => $range->toDate()])); @endphp
            <a class="btn btn-secondary" href="{{ route('dashboard.events.attendance.export', $exportParams) }}">Export CSV</a>
        @endif
    </div>

    @includeWhen(isset($range), 'dashboard.events._report_date_filter', ['action' => 'dashboard.events.attendance'])

    @if ($attendance->confirmedOrders === 0)
        <div class="empty-state">
            <h2>No confirmed orders yet</h2>
            <p>Once tickets are sold and scanned at the door, attendance will show here.</p>
        </div>
    @else
        {{-- Headline figures. --}}
        <div class="summary-row">
            <div class="metric-card">
                <span class="metric-card__label">Checked in</span>
                <span class="metric-card__value" data-metric="checked_in_orders">{{ number_format($attendance->checkedInOrders) }}</span>
                <span class="metric-card__sub">of {{ number_format($attendance->confirmedOrders) }} orders</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Attendance rate</span>
                <span class="metric-card__value" data-metric="attendance_rate">{{ $pct($attendance->attendanceRate()) }}</span>
                <span class="metric-card__sub">by order</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">No-shows</span>
                <span class="metric-card__value" data-metric="no_show_orders">{{ number_format($attendance->noShowOrders()) }}</span>
                <span class="metric-card__sub">{{ $pct($attendance->noShowRate()) }} of orders</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">People arrived</span>
                <span class="metric-card__value" data-metric="tickets_checked_in">{{ number_format($attendance->ticketsCheckedIn) }}</span>
                <span class="metric-card__sub">of {{ number_format($attendance->ticketsSold) }} tickets</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Headcount rate</span>
                <span class="metric-card__value" data-metric="headcount_rate">{{ $pct($attendance->headcountRate()) }}</span>
                <span class="metric-card__sub">by ticket</span>
            </div>
        </div>

        {{-- First / last scan, so the door window is obvious. --}}
        <div class="panel">
            <div class="panel__head"><h2>Check-in window</h2></div>
            <table class="data-table">
                <tbody>
                    <tr>
                        <th scope="row"><span class="cell-strong">First check-in</span></th>
                        <td class="num" data-metric="first_check_in">{{ $fmtTime($attendance->firstCheckInAt) }}</td>
                    </tr>
                    <tr>
                        <th scope="row"><span class="cell-strong">Last check-in</span></th>
                        <td class="num" data-metric="last_check_in">{{ $fmtTime($attendance->lastCheckInAt) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Who scanned attendees in. --}}
        <div class="panel">
            <div class="panel__head"><h2>Checked in by</h2></div>
            @if (empty($attendance->byStaff))
                <div class="empty"><p>No check-ins recorded yet.</p></div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Team member</th>
                            <th scope="col" class="num">Orders</th>
                            <th scope="col" class="num">Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attendance->byStaff as $row)
                            <tr>
                                <td><span class="cell-strong">{{ $row['name'] }}</span></td>
                                <td class="num">{{ number_format($row['orders']) }}</td>
                                <td class="num">{{ number_format($row['tickets']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Arrival curve over time (hourly). --}}
        <div class="panel">
            <div class="panel__head"><h2>Arrivals over time</h2></div>
            @if (empty($attendance->timeline))
                <div class="empty"><p>No check-ins recorded yet.</p></div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Hour</th>
                            <th scope="col" class="num">Orders</th>
                            <th scope="col" class="num">Tickets</th>
                            <th scope="col"><span class="sr-only">Share of peak hour</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attendance->timeline as $row)
                            @php $width = $peak > 0 ? max(2, round($row['tickets'] / $peak * 100)) : 0; @endphp
                            <tr>
                                <td><span class="cell-strong">{{ $fmtHour($row['hour']) }}</span></td>
                                <td class="num">{{ number_format($row['orders']) }}</td>
                                <td class="num">{{ number_format($row['tickets']) }}</td>
                                <td>
                                    <span class="attendance-bar" aria-hidden="true">
                                        <span class="attendance-bar__fill" style="width: {{ $width }}%"></span>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
@endsection

@push('head')
<style>
    .panel__head--bare { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; padding: 0 0 1rem; border: 0; }
    .metric-card__sub { display: block; font-size: 0.78rem; color: var(--muted, #667085); margin-top: 0.15rem; }
    .attendance-bar { display: block; height: 0.5rem; min-width: 3rem; background: var(--border, #e5e7eb); border-radius: 999px; overflow: hidden; }
    .attendance-bar__fill { display: block; height: 100%; background: var(--brand, #2563eb); border-radius: 999px; }
</style>
@endpush
