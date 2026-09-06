<?php

namespace App\Rules;

use App\Models\Company;
use App\Models\ReservedSlug;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a Company_Slug per Requirement 1.6:
 *   - 1 to 255 characters,
 *   - lowercase alphanumeric characters and hyphens only,
 *   - not on the reserved-slug blocklist (see {@see ReservedSlug}),
 *   - unique across all Companies.
 *
 * The reserved-slug check rejects values that collide with reserved platform
 * routes/infrastructure paths (which path-based tenancy would otherwise shadow)
 * or brand/abuse words the Platform declines to hand out; the list is managed by
 * a Super_Admin on the `/admin` surface.
 *
 * The uniqueness check may exclude a Company by id so an existing Company can
 * revalidate its own slug on update.
 */
class CompanySlug implements ValidationRule
{
    /**
     * Pattern for a syntactically valid slug: one or more lowercase
     * alphanumeric characters or hyphens, anchored end to end.
     */
    public const PATTERN = '/^[a-z0-9-]+$/';

    /**
     * Maximum slug length in characters.
     */
    public const MAX_LENGTH = 255;

    public function __construct(private ?int $ignoreCompanyId = null) {}

    /**
     * Whether the candidate slug is syntactically valid (format + length),
     * ignoring uniqueness. Useful for callers that only need the format check.
     */
    public static function isValidFormat(mixed $slug): bool
    {
        if (! is_string($slug)) {
            return false;
        }

        $length = strlen($slug);

        if ($length < 1 || $length > self::MAX_LENGTH) {
            return false;
        }

        return preg_match(self::PATTERN, $slug) === 1;
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be between 1 and 255 characters.');

            return;
        }

        if (strlen($value) > self::MAX_LENGTH) {
            $fail('The :attribute must not be greater than 255 characters.');

            return;
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute may only contain lowercase letters, numbers, and hyphens.');

            return;
        }

        // Reject slugs on the blocklist: reserved platform routes / infra paths
        // (which the storefront catch-all would otherwise shadow) and
        // brand/abuse words the Platform declines to hand out. Checked before
        // the (more expensive) uniqueness query and reported as "not available"
        // so we do not disclose the blocklist's exact contents.
        if (ReservedSlug::isReserved($value)) {
            $fail('The :attribute is not available.');

            return;
        }

        $query = Company::query()->where('slug', $value);

        if ($this->ignoreCompanyId !== null) {
            $query->whereKeyNot($this->ignoreCompanyId);
        }

        if ($query->exists()) {
            $fail('The :attribute has already been taken.');
        }
    }
}
