<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\OrderConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An OrderConsent captures a single consent selection the Customer made at
 * checkout (e.g. `terms`, `privacy`, `marketing`), stored on the Order so the
 * accepted/declined state is retained. (Requirements 10.3, 10.4, 22.4)
 *
 * OrderConsents are Company-owned: the {@see BelongsToCompany} trait registers
 * the global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so a consent record always belongs to the active
 * Company and cross-Company rows never match.
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property string $consent_key
 * @property bool $accepted
 * @property Carbon $captured_at
 */
class OrderConsent extends Model
{
    /** @use HasFactory<OrderConsentFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'consent_key',
        'accepted',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    /**
     * The Order this consent selection was captured for.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
