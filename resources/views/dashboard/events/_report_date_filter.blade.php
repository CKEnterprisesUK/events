{{--
    Shared date-range filter for the per-event report screens (sales, attendance,
    attendee answers). Submits `from`/`to` (both optional, inclusive, Y-m-d) back
    to the current report route via GET, so the figures and any export links
    narrow to the chosen period. Leaving both blank shows all time.

    Expects:
      $event  — the Event being reported on.
      $range  — an App\Services\Reporting\ReportDateRange (from the controller).
      $action — the route name of the report screen to submit back to.
--}}
@php
    /** @var \App\Services\Reporting\ReportDateRange $range */
    $from = $range->fromDate();
    $to = $range->toDate();
@endphp

<form method="GET" action="{{ route($action, $event) }}" class="report-filter">
    <div class="field">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="{{ $from ?? '' }}">
    </div>
    <div class="field">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="{{ $to ?? '' }}">
    </div>
    <div class="report-filter__actions">
        <button type="submit" class="btn btn-sm">Apply</button>
        @if ($range->isBounded())
            <a class="btn btn-sm btn-outline" href="{{ route($action, $event) }}">Clear</a>
        @endif
    </div>
</form>

@if ($range->isBounded())
    <p class="panel__note" data-report-period>Showing {{ $range->label() }}.</p>
@endif
