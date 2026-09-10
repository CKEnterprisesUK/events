<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An OrderQuestionAnswer captures the Customer's answer to one custom
 * {@see EventQuestion} at checkout, stored on the Order so the response is
 * retained for reporting. Modelled on {@see OrderConsent}.
 *
 * `question_label` snapshots the question text at answer time so a report still
 * reads correctly even if the organiser later edits or deletes the question.
 * The `event_question_id` is nulled (not the row deleted) when the question is
 * removed, so the answer and its snapshot label survive.
 *
 * Answers are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the resolved
 * tenant on create.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int|null $event_question_id
 * @property string $question_label
 * @property string|null $answer
 * @property Carbon $captured_at
 */
class OrderQuestionAnswer extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'event_question_id',
        'question_label',
        'answer',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    /**
     * The Order this answer was captured for.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The question that was answered. Null if the question was later removed.
     *
     * @return BelongsTo<EventQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(EventQuestion::class, 'event_question_id');
    }
}
