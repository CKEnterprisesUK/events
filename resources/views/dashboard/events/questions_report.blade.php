{{--
    Attendee answers report: per-question response stats for this Event, with a
    CSV export. Read-only; gated on ACTION_VIEW_REPORTS in the controller.

    Expects:
      $event         — the Event.
      $questions     — the Event's configured questions (0–3).
      $stats         — per-question stats keyed by question id:
                       ['answered' => int, 'breakdown' => [option => count]].
      $responseCount — number of confirmed orders considered.
--}}
@extends('layouts.event')

@section('active_section', 'questions')

@section('section')
    <div class="panel">
        <div class="panel__head panel__head--split">
            <h2>Attendee answers</h2>
            @if ($questions->isNotEmpty() && $responseCount > 0)
                @php $exportParams = array_merge(['event' => $event], isset($range) ? array_filter(['from' => $range->fromDate(), 'to' => $range->toDate()]) : []); @endphp
                <a class="btn btn-secondary" href="{{ route('dashboard.events.questions.export', $exportParams) }}">
                    Export CSV
                </a>
            @endif
        </div>

        <p class="hint">
            Based on {{ $responseCount }} confirmed {{ \Illuminate\Support\Str::plural('order', $responseCount) }}.
            <a href="{{ route('dashboard.events.questions', $event) }}">Edit questions</a>
        </p>

        @includeWhen(isset($range) && $questions->isNotEmpty(), 'dashboard.events._report_date_filter', ['action' => 'dashboard.events.questions.report'])

        @if ($questions->isEmpty())
            <p class="empty-state">
                This event has no attendee questions yet.
                <a href="{{ route('dashboard.events.questions', $event) }}">Add up to three questions</a>
                to collect information at checkout.
            </p>
        @elseif ($responseCount === 0)
            <p class="empty-state">No confirmed orders yet, so there are no answers to report.</p>
        @else
            @foreach ($questions as $question)
                @php $stat = $stats[$question->id] ?? ['answered' => 0, 'breakdown' => [], 'numeric' => null, 'words' => []]; @endphp
                <section class="report-question">
                    <h3>{{ $question->label }}</h3>
                    <p class="muted">
                        {{ $stat['answered'] }} of {{ $responseCount }} answered
                        @if ($question->required) &middot; required @endif
                    </p>

                    @if ($question->isSelect())
                        {{-- Single-choice: a horizontal bar chart of each option's
                             share of respondents, with the exact counts beside
                             the table below for precise figures. --}}
                        @php
                            $bars = [];
                            foreach ($stat['breakdown'] as $choice => $count) {
                                $bars[] = ['label' => $choice, 'value' => $count];
                            }
                        @endphp
                        @include('dashboard.partials.bar-chart', [
                            'bars' => $bars,
                            'total' => $stat['answered'],
                        ])

                    @elseif ($question->isNumber())
                        {{-- Numeric: headline min / average / max, plus a
                             histogram of the distribution across the range. --}}
                        @if (! empty($stat['numeric']))
                            @php $n = $stat['numeric']; @endphp
                            <div class="report-numeric">
                                <div class="report-numeric__stat">
                                    <span class="report-numeric__label">Lowest</span>
                                    <span class="report-numeric__value">{{ rtrim(rtrim(number_format($n['min'], 2), '0'), '.') }}</span>
                                </div>
                                <div class="report-numeric__stat">
                                    <span class="report-numeric__label">Average</span>
                                    <span class="report-numeric__value">{{ number_format($n['average'], 1) }}</span>
                                </div>
                                <div class="report-numeric__stat">
                                    <span class="report-numeric__label">Highest</span>
                                    <span class="report-numeric__value">{{ rtrim(rtrim(number_format($n['max'], 2), '0'), '.') }}</span>
                                </div>
                                <div class="report-numeric__stat">
                                    <span class="report-numeric__label">Answers</span>
                                    <span class="report-numeric__value">{{ number_format($n['count']) }}</span>
                                </div>
                            </div>
                            <h4 class="report-question__subhead">Distribution</h4>
                            @include('dashboard.partials.bar-chart', [
                                'bars' => $n['histogram'],
                                'total' => $n['count'],
                            ])
                        @else
                            <p class="muted">No numeric answers yet. Full answers are in the CSV export.</p>
                        @endif

                    @else
                        {{-- Free text: a word cloud of the most common terms,
                             with every full answer still in the CSV export. --}}
                        @if (! empty($stat['words']))
                            @include('dashboard.partials.word-cloud', ['words' => $stat['words']])
                        @else
                            <p class="muted">No free-text answers yet. Full answers are in the CSV export.</p>
                        @endif
                    @endif
                </section>
            @endforeach
        @endif
    </div>
@endsection
