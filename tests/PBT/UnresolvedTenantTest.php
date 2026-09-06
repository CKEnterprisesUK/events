<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * Property-based test for unresolved / missing tenant resolution
 * (Property 2).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * Two complementary halves:
 *   - A leading path segment that matches no Company establishes no active
 *     Company, returns HTTP 404 for slug paths, and denies all Company-owned
 *     records. (Requirements 1.3, 1.7)
 *   - A slug that DOES match a Company resolves that same Company regardless of
 *     the letter-case supplied in the request. (Requirement 1.2)
 */
class UnresolvedTenantTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The one Company that exists during the "matching slug" half of the
     * property. Stored lowercase (Requirement 1.6) so requests in any
     * letter-case must still resolve to it.
     */
    private const EXISTING_SLUG = 'bright-events';

    protected function setUp(): void
    {
        parent::setUp();

        // The `widgets` helper table is provided by the testing-only
        // create_test_support_tables migration (no runtime DDL here, so the
        // RefreshDatabase transaction stays intact).

        // A route inside the tenant group so ResolveTenant + the global scope
        // run exactly as in production. It reports the resolved company_id (or
        // null) and the labels visible under the resolved tenant.
        Route::middleware('tenant')->group(function () {
            Route::get('/{companySlug}/widgets', function (TenantContext $ctx) {
                return response()->json([
                    'company_id' => $ctx->companyId(),
                    'labels' => Widget::query()->pluck('label'),
                ]);
            })->where('companySlug', '[A-Za-z0-9-]+');
        });
    }

    /**
     * Character pool for generated slug segments: lowercase alphanumeric and
     * hyphens only, so every generated candidate is a routable slug-shaped
     * segment (the route constrains `companySlug` to `[A-Za-z0-9-]+`).
     *
     * @var list<string>
     */
    private const SLUG_CHAR_POOL = ['a', 'b', 'c', 'k', 'q', 'z', '0', '3', '7', '9', '-'];

    /**
     * A generator producing non-empty slug-shaped strings.
     *
     * A guaranteed leading character is concatenated with a (possibly empty)
     * sequence so the result is always at least one character long and always
     * matches the route's `[A-Za-z0-9-]+` constraint.
     */
    private function slugSegmentGenerator(): Generator
    {
        return Generator\map(
            fn (array $parts): string => $parts[0].implode('', $parts[1]),
            Generator\tuple(
                Generator\elements(...self::SLUG_CHAR_POOL),
                Generator\seq(Generator\elements(...self::SLUG_CHAR_POOL))
            )
        );
    }

    /**
     * Property 2: Unresolved / missing tenant establishes no Company — a leading
     * path segment that matches no Company yields no active Company, an HTTP 404
     * for slug paths, and denies access to all Company-owned records.
     *
     * **Validates: Requirements 1.3, 1.7**
     */
    // Feature: event-ticketing-platform, Property 2: Unresolved / missing tenant establishes no Company — non-matching leading segment → no Company + 404 for slug paths + deny Company-owned records; valid slug resolves the same Company in any letter-case
    public function test_non_matching_segment_establishes_no_company_and_denies_records(): void
    {
        // One real Company exists, owning one Company-owned record. A request
        // whose leading segment does not match this Company's slug must never
        // resolve it, and its record must never be visible.
        $company = Company::factory()->create(['slug' => self::EXISTING_SLUG]);
        Widget::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'label' => 'owned',
        ]);

        $this->forAll($this->slugSegmentGenerator())
            ->withMaxSize(40)
            ->then(function (string $segment) use ($company): void {
                // Restrict to segments that genuinely do not match the existing
                // slug (case-insensitively). Eris' filtering keeps the input
                // space to true non-matches so 404 is the expected outcome.
                if (strtolower($segment) === strtolower(self::EXISTING_SLUG)) {
                    return;
                }

                // A slug path with no matching Company is denied with 404 and
                // establishes no active Company. (Requirements 1.3, 1.7)
                $this->getJson("/{$segment}/widgets")->assertNotFound();

                // No Company leaked into the shared request-scoped context.
                $this->assertFalse(
                    app(TenantContext::class)->hasCompany(),
                    sprintf('Segment %s must establish no active Company.', var_export($segment, true))
                );

                // With no resolved Company the global scope denies every
                // Company-owned record. (Requirement 1.7)
                $this->assertCount(
                    0,
                    Widget::query()->get(),
                    sprintf('Company-owned records must be denied for segment %s.', var_export($segment, true))
                );
            });
    }

    /**
     * Property 2 (matching half): a slug that matches a Company resolves that
     * same Company regardless of the letter-case supplied in the request path.
     *
     * **Validates: Requirements 1.2**
     */
    // Feature: event-ticketing-platform, Property 2: Unresolved / missing tenant establishes no Company — non-matching leading segment → no Company + 404 for slug paths + deny Company-owned records; valid slug resolves the same Company in any letter-case
    public function test_matching_slug_resolves_same_company_in_any_letter_case(): void
    {
        $company = Company::factory()->create(['slug' => self::EXISTING_SLUG]);

        // Generate an independent letter-case decision for each character of the
        // stored slug, then request the resulting mixed-case variant.
        $this->forAll(
            Generator\vector(strlen(self::EXISTING_SLUG), Generator\bool())
        )
            ->then(function (array $upperFlags) use ($company): void {
                $variant = '';
                foreach (str_split(self::EXISTING_SLUG) as $index => $char) {
                    $variant .= ($upperFlags[$index] ?? false) ? strtoupper($char) : $char;
                }

                // Any letter-case of a valid slug resolves the same Company.
                // (Requirement 1.2)
                $this->getJson("/{$variant}/widgets")
                    ->assertOk()
                    ->assertJsonPath('company_id', $company->id);
            });
    }
}

/**
 * Test-only Company-owned model. Uses the production trait so this property
 * exercises the real global scope and auto-fill behaviour.
 */
class Widget extends Model
{
    use BelongsToCompany;

    protected $table = 'widgets';

    protected $guarded = [];
}
