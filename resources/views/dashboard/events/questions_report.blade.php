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
                <a class="btn btn-secondary" href="{{ route('dashboard.events.questions.export', $event) }}">
                    Export CSV
                </a>
            @endif
        </div>

        <p class="hint">
            Based on {{ $responseCount }} confirmed {{ \Illuminate\Support\Str::plural('order', $responseCount) }}.
            <a href="{{ route('dashboard.events.questions', $event) }}">Edit questions</a>
        </p>

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
                @php $stat = $stats[$question->id] ?? ['answered' => 0, 'breakdown' => []]; @endphp
                <section class="report-question">
                    <h3>{{ $question->label }}</h3>
                    <p class="muted">
                        {{ $stat['answered'] }} of {{ $responseCount }} answered
                        @if ($question->required) &middot; required @endif
                    </p>

                    @if ($question->isSelect())
                        <table class="table">
                            <thead>
                                <tr><th scope="col">Choice</th><th scope="col">Responses</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($stat['breakdown'] as $choice => $count)
                                    <tr>
                                        <td>{{ $choice }}</td>
                                        <td>{{ $count }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="muted">
                            {{ $question->isNumber() ? 'Numeric answers' : 'Free-text answers' }}
                            are listed per order in the CSV export.
                        </p>
                    @endif
                </section>
            @endforeach
        @endif
    </div>
@endsection
