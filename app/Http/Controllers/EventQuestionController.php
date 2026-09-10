<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\EventReportService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manage the custom questions asked of the Customer at checkout for an Event.
 *
 * An Event may have up to {@see EventQuestion::MAX_PER_EVENT} questions. The
 * whole set is edited on one screen and saved atomically: `save()` replaces the
 * Event's questions with exactly the submitted rows, so removing a question is
 * simply leaving it out of the form. Write access is gated by
 * `ACTION_MANAGE_EVENTS` (Admin, plus Super_Admin via the Gate::before bypass);
 * Events are tenant-scoped, so a foreign Event 404s and is never modified.
 */
class EventQuestionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The attendee-questions screen: the current question set (padded to the
     * three editable slots in the view) for this Event.
     */
    public function index(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.questions', [
            'event' => $event,
            'questions' => $event->questions()->get(),
            'maxQuestions' => EventQuestion::MAX_PER_EVENT,
        ]);
    }

    /**
     * Replace the Event's questions with the submitted set (0–3 questions).
     *
     * Each submitted question carries a type, a label, a required flag and —
     * for `select` questions — a list of options. Blank rows (no label) are
     * dropped so an organiser can clear a slot by emptying it. The replace is
     * done in a transaction: the old rows are deleted and the new set inserted
     * in form order, which becomes their `position`.
     */
    public function save(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $questions = $this->validated($request);

        DB::transaction(function () use ($event, $questions): void {
            // Replace the whole set: the answers already captured on past Orders
            // snapshot their own question label and null their FK on delete, so
            // dropping a question never orphans or corrupts an existing answer.
            $event->questions()->delete();

            foreach ($questions as $position => $question) {
                $event->questions()->create([
                    'type' => $question['type'],
                    'label' => $question['label'],
                    'options' => $question['options'],
                    'required' => $question['required'],
                    'position' => $position,
                ]);
            }
        });

        $this->audit->record(
            action: AuditLog::EVENT_QUESTIONS_UPDATED,
            auditable: $event,
            summary: 'Updated attendee questions for "'.$event->name.'" ('.count($questions).' question'.(count($questions) === 1 ? '' : 's').')',
        );

        return redirect()
            ->route('dashboard.events.questions', $event)
            ->with('status', 'Attendee questions saved.');
    }

    /**
     * The answers report for this Event: per-question response counts and, for
     * select questions, a breakdown by option — plus the recent orders with
     * their answers. Gated on ACTION_VIEW_REPORTS (Accountant/Owner, plus
     * Super_Admin), matching the sales reports.
     */
    public function report(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        $questions = $event->questions()->get();
        $orders = $this->confirmedOrders($event);

        return view('dashboard.events.questions_report', [
            'event' => $event,
            'questions' => $questions,
            'stats' => $this->buildStats($questions, $orders),
            'responseCount' => $orders->count(),
        ]);
    }

    /**
     * Stream a CSV of every confirmed order's answers for this Event: one row
     * per order, one column per configured question (plus order reference,
     * customer name/email and date). Uses the same streamDownload + fputcsv
     * shape as the company sales export.
     */
    public function export(Event $event): StreamedResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        $questions = $event->questions()->get();
        $orders = $this->confirmedOrders($event);

        $slug = \Illuminate\Support\Str::slug($event->name) ?: 'event';
        $filename = 'attendee-answers-'.$slug.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($questions, $orders): void {
            $out = fopen('php://output', 'wb');

            // Header row: order metadata columns, then one column per question,
            // labelled with the current question text.
            $header = ['Order reference', 'Customer name', 'Email', 'Date'];
            foreach ($questions as $question) {
                $header[] = $question->label;
            }
            fputcsv($out, $header);

            foreach ($orders as $order) {
                // Index this order's answers by question id for a quick lookup.
                $byQuestion = $order->questionAnswers->keyBy('event_question_id');

                $row = [
                    $order->order_reference,
                    $order->customer_name,
                    $order->customer_email,
                    optional($order->created_at)->format('Y-m-d H:i'),
                ];

                foreach ($questions as $question) {
                    $answer = $byQuestion->get($question->id);
                    $row[] = $answer?->answer ?? '';
                }

                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The Event's confirmed Orders (paid or free-confirmed) with their answers
     * eager-loaded, most recent first. Only confirmed orders are counted so a
     * report never reflects abandoned/reserved carts. Tenant-scoped via the
     * global company scope.
     *
     * @return EloquentCollection<int, Order>
     */
    private function confirmedOrders(Event $event): EloquentCollection
    {
        return $event->orders()
            ->whereIn('status', EventReportService::CONFIRMED_STATUSES)
            ->with('questionAnswers')
            ->latest()
            ->get();
    }

    /**
     * Build per-question response stats: the number of orders that answered,
     * and — for select questions — how many chose each option.
     *
     * @param  EloquentCollection<int, EventQuestion>  $questions
     * @param  EloquentCollection<int, Order>  $orders
     * @return array<int, array{answered:int, breakdown:array<string,int>}>
     */
    private function buildStats(EloquentCollection $questions, EloquentCollection $orders): array
    {
        $stats = [];

        foreach ($questions as $question) {
            $answered = 0;
            $breakdown = [];

            // Pre-seed select options at zero so unpicked choices still show.
            if ($question->isSelect()) {
                foreach ($question->choices() as $choice) {
                    $breakdown[$choice] = 0;
                }
            }

            foreach ($orders as $order) {
                $answer = $order->questionAnswers->firstWhere('event_question_id', $question->id);
                $value = $answer?->answer;

                if ($value === null || $value === '') {
                    continue;
                }

                $answered++;

                if ($question->isSelect()) {
                    $breakdown[$value] = ($breakdown[$value] ?? 0) + 1;
                }
            }

            $stats[$question->id] = [
                'answered' => $answered,
                'breakdown' => $breakdown,
            ];
        }

        return $stats;
    }

    /**
     * Validate the submitted questions and normalise them into a clean,
     * 0-indexed list of storable rows, dropping empty slots.
     *
     * Rules per question:
     *   - type:     one of {@see EventQuestion::TYPES}.
     *   - label:    1–255 chars (a row with no label is treated as empty and
     *               dropped before validation).
     *   - options:  required for `select` (2–20 non-empty choices, each ≤255);
     *               ignored/nulled otherwise.
     *   - required: boolean.
     *
     * @return array<int, array{type:string, label:string, options:array<int,string>|null, required:bool}>
     */
    private function validated(Request $request): array
    {
        // Keep only rows the organiser actually filled in (a non-empty label).
        // This lets the fixed three-slot form leave gaps and clear questions.
        $rows = collect($request->input('questions', []))
            ->filter(fn ($row): bool => is_array($row) && trim((string) ($row['label'] ?? '')) !== '')
            ->values();

        // Guard the ceiling before validating individual rows.
        abort_if($rows->count() > EventQuestion::MAX_PER_EVENT, 422);

        $request->merge(['questions' => $rows->all()]);

        $validated = $request->validate([
            'questions' => ['nullable', 'array', 'max:'.EventQuestion::MAX_PER_EVENT],
            'questions.*.type' => ['required', Rule::in(EventQuestion::TYPES)],
            'questions.*.label' => ['required', 'string', 'min:1', 'max:255'],
            'questions.*.required' => ['nullable', 'boolean'],
            // Raw options come in as a newline/comma-free textarea; each line is
            // one choice. Validated as a string here and split below.
            'questions.*.options' => ['nullable', 'string', 'max:2000'],
        ], [
            'questions.*.type.in' => __('Choose a valid question type.'),
            'questions.*.label.required' => __('Enter the question text.'),
        ]);

        $result = [];

        foreach ($validated['questions'] ?? [] as $index => $row) {
            $type = $row['type'];
            $options = null;

            if ($type === EventQuestion::TYPE_SELECT) {
                $options = $this->parseOptions((string) ($row['options'] ?? ''));

                // A single-choice question needs at least two options to be a
                // meaningful choice, and we cap the list to keep it usable.
                if (count($options) < 2) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "questions.{$index}.options" => __('A multiple-choice question needs at least two options, one per line.'),
                    ]);
                }

                if (count($options) > 20) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "questions.{$index}.options" => __('A multiple-choice question may have at most 20 options.'),
                    ]);
                }
            }

            $result[] = [
                'type' => $type,
                'label' => trim($row['label']),
                'options' => $options,
                'required' => (bool) ($row['required'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * Split a textarea of options (one per line) into a clean, de-duplicated
     * list of non-empty choices, each trimmed and capped at 255 chars.
     *
     * @return array<int, string>
     */
    private function parseOptions(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        $choices = [];
        foreach ($lines as $line) {
            $choice = trim($line);
            if ($choice === '') {
                continue;
            }

            $choice = mb_substr($choice, 0, 255);

            if (! in_array($choice, $choices, true)) {
                $choices[] = $choice;
            }
        }

        return $choices;
    }
}
