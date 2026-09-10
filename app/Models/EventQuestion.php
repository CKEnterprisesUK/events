<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An EventQuestion is one custom question an organiser configures for an Event,
 * asked of the Customer at checkout. An Event may carry at most
 * {@see self::MAX_PER_EVENT} questions (enforced in the controller, not the
 * schema).
 *
 * Questions are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the resolved
 * tenant on create, so a question always belongs to the active Company and
 * cross-Company rows never match.
 *
 * @property int $id
 * @property int $company_id
 * @property int $event_id
 * @property string $type
 * @property string $label
 * @property array<int, string>|null $options
 * @property bool $required
 * @property int $position
 */
class EventQuestion extends Model
{
    use BelongsToCompany;

    /**
     * A single-line free-text answer.
     */
    public const TYPE_FREE_TEXT = 'free_text';

    /**
     * A single choice from a fixed list, offered to the Customer as radios.
     */
    public const TYPE_SELECT = 'select';

    /**
     * A numeric answer.
     */
    public const TYPE_NUMBER = 'number';

    /**
     * The permitted question types.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_FREE_TEXT,
        self::TYPE_SELECT,
        self::TYPE_NUMBER,
    ];

    /**
     * The most questions an Event may ask at checkout.
     */
    public const MAX_PER_EVENT = 3;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'event_id',
        'type',
        'label',
        'options',
        'required',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * The Event this question belongs to.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Whether this is a single-choice (radio) question, i.e. it carries a list
     * of options the Customer picks from.
     */
    public function isSelect(): bool
    {
        return $this->type === self::TYPE_SELECT;
    }

    /**
     * Whether this question expects a numeric answer.
     */
    public function isNumber(): bool
    {
        return $this->type === self::TYPE_NUMBER;
    }

    /**
     * The choices for a select question as a clean list of non-empty strings.
     * Empty for non-select questions.
     *
     * @return array<int, string>
     */
    public function choices(): array
    {
        if (! $this->isSelect()) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $this->options ?? []),
            static fn (string $option): bool => $option !== '',
        ));
    }
}
