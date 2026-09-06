<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Rules\CompanySlug;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for Company_Slug validity (Requirement 1.6).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy.
 */
class CompanySlugValidityTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * A slug already used by an existing Company. Candidates equal to this must
     * be rejected on uniqueness grounds even when their format is valid.
     */
    private const USED_SLUG = 'already-taken-slug';

    /**
     * Character pool spanning both permitted and forbidden characters, so the
     * generator produces a mix of valid slugs (lowercase alphanumeric +
     * hyphens) and invalid ones (uppercase, symbols, whitespace, unicode).
     *
     * @var list<string>
     */
    private const CHAR_POOL = [
        // Permitted: lowercase alphanumeric and hyphen.
        'a', 'b', 'm', 'z', '0', '5', '9', '-',
        // Forbidden: uppercase, symbols, whitespace, unicode.
        'A', 'Z', ' ', '_', '.', '/', '@', '#', '!', '%', "\t", "\n", 'é',
    ];

    /**
     * Run the CompanySlug rule against a value and report whether it accepted.
     *
     * The rule signals rejection by invoking the `$fail` closure; if it is
     * never called the value is accepted. This captures the full accept/reject
     * decision, including the DB uniqueness check.
     */
    private function ruleAccepts(mixed $value): bool
    {
        $failed = false;

        (new CompanySlug())->validate(
            'slug',
            $value,
            function () use (&$failed): void {
                $failed = true;
            }
        );

        return ! $failed;
    }

    /**
     * The independently-computed oracle: a slug is valid iff it is 1–255
     * characters, matches lowercase alphanumeric + hyphens, and is not the
     * already-used slug.
     */
    private function expectedAccepted(string $candidate): bool
    {
        $length = strlen($candidate);

        $formatValid = $length >= 1
            && $length <= CompanySlug::MAX_LENGTH
            && preg_match('/^[a-z0-9-]+$/', $candidate) === 1;

        return $formatValid && $candidate !== self::USED_SLUG;
    }

    /**
     * Property 3: Slug validity — the Platform accepts a candidate slug if and
     * only if it is 1–255 characters, consists solely of lowercase alphanumeric
     * characters and hyphens, and is not already used by another Company.
     *
     * **Validates: Requirements 1.6**
     */
    // Feature: event-ticketing-platform, Property 3: Slug validity — accept iff 1–255 chars, lowercase alphanumeric + hyphens, and not already used
    public function test_slug_accepted_iff_valid_format_and_not_already_used(): void
    {
        Company::factory()->create(['slug' => self::USED_SLUG]);

        // Fixed boundary cases guarantee coverage the random generator reaches
        // rarely: empty, single char, exactly the max length, one over the max,
        // and the already-used slug.
        $boundaryCases = [
            '',                                        // empty -> invalid
            'a',                                       // min length, valid
            str_repeat('a', CompanySlug::MAX_LENGTH),  // exactly 255, valid
            str_repeat('a', CompanySlug::MAX_LENGTH + 1), // 256, over-length -> invalid
            self::USED_SLUG,                           // valid format but taken -> invalid
            'has space',                               // whitespace -> invalid
            'Upper',                                   // uppercase -> invalid
        ];

        foreach ($boundaryCases as $candidate) {
            $this->assertSame(
                $this->expectedAccepted($candidate),
                $this->ruleAccepts($candidate),
                sprintf('Boundary slug %s', var_export($candidate, true))
            );
        }

        // Random exploration across a mixed character pool and lengths that can
        // exceed the 255-char limit (withMaxSize controls the sequence length).
        $this->forAll(
            Generator\map(
                fn (array $chars): string => implode('', $chars),
                Generator\seq(Generator\elements(...self::CHAR_POOL))
            )
        )
            ->withMaxSize(300)
            ->then(function (string $candidate): void {
                $this->assertSame(
                    $this->expectedAccepted($candidate),
                    $this->ruleAccepts($candidate),
                    sprintf('Slug %s (len=%d)', var_export($candidate, true), strlen($candidate))
                );

                // The pure format helper must agree with the format-only part
                // of the decision (it does not consider uniqueness).
                $length = strlen($candidate);
                $formatValid = $length >= 1
                    && $length <= CompanySlug::MAX_LENGTH
                    && preg_match('/^[a-z0-9-]+$/', $candidate) === 1;

                $this->assertSame(
                    $formatValid,
                    CompanySlug::isValidFormat($candidate),
                    'isValidFormat must reflect the format-only decision.'
                );
            });
    }
}
