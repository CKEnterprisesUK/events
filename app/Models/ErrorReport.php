<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A captured uncaught server error (HTTP 500). In production (`APP_DEBUG=false`)
 * the exception handler stores the raw diagnostic detail here and shows the
 * visitor a branded page carrying only the short, quotable {@see $reference}
 * (e.g. `ERR-3F9A2B7C`). A Super_Admin looks that reference up on the
 * `/admin/errors` surface to see the full exception behind it — the customer
 * never sees a stack trace, but nothing is lost.
 *
 * Deliberately NOT tenant-scoped (no {@see BelongsToCompany} trait): an error
 * can occur on the platform surface, on a webhook, or before a tenant is even
 * resolved, so it may have no Company and no acting user. `company_id`/`user_id`
 * are plain nullable columns; the admin surface reads across every tenant.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $company_id
 * @property int|null $user_id
 * @property string|null $exception_class
 * @property string|null $message
 * @property string|null $file
 * @property int|null $line
 * @property int $status_code
 * @property string|null $method
 * @property string|null $url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $trace
 * @property array<string, mixed>|null $context
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ErrorReport extends Model
{
    /**
     * Prefix on every customer-facing reference. Kept short and unambiguous so
     * a customer can read it out over the phone or paste it into a support
     * ticket.
     */
    public const REFERENCE_PREFIX = 'ERR-';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'company_id',
        'user_id',
        'exception_class',
        'message',
        'file',
        'line',
        'status_code',
        'method',
        'url',
        'ip_address',
        'user_agent',
        'trace',
        'context',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line' => 'integer',
            'status_code' => 'integer',
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Generate a fresh, unique customer-facing reference such as `ERR-3F9A2B7C`.
     *
     * The random part is uppercase hex so it is unambiguous when read aloud and
     * carries no sequential information (a customer can't infer error volume).
     * A short loop guards against the vanishingly rare collision on the unique
     * `reference` column.
     */
    public static function generateReference(): string
    {
        do {
            $reference = self::REFERENCE_PREFIX.strtoupper(Str::random(8));
        } while (self::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * The Company in context when the error occurred (NULL = platform/system/
     * pre-tenant).
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The authenticated user in context when the error occurred (NULL = guest/
     * customer/system).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether a Super_Admin has marked this report handled.
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * The exception class without its namespace, for a compact list display.
     */
    public function shortExceptionClass(): string
    {
        if ($this->exception_class === null || $this->exception_class === '') {
            return 'Error';
        }

        return class_basename($this->exception_class);
    }
}
