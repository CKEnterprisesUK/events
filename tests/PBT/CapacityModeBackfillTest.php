<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Property-based test for the capacity-mode migration backfill classification
 * rule (Requirement 2.9).
 *
 * The migration `2024_01_01_001900_add_capacity_mode_to_ticket_types` (and the
 * matching `database/sql/020_add_capacity_mode_to_ticket_types.sql`) backfills
 * existing rows with:
 *
 *     UPDATE ticket_types tt
 *     JOIN events e ON e.id = tt.event_id
 *     SET tt.capacity_mode = CASE
 *         WHEN e.capacity IS NOT NULL AND tt.capacity = e.capacity THEN 'shared_pool'
 *         ELSE 'capped'
 *     END
 *
 * Under RefreshDatabase the migration has already run, so testing the raw
 * migration UPDATE against the migration's own moment is not possible. Instead
 * this property exercises the backfill CLASSIFICATION RULE directly: it seeds
 * generated Event + Ticket_Type rows (all initialised to a placeholder
 * `capped` mode), runs the migration's EXACT backfill SQL scoped to the current
 * event's types, and asserts each row's resulting `capacity_mode` equals the
 * independently computed oracle — `shared_pool` iff the type's capacity equalled
 * its event's non-null overall capacity, otherwise `capped`.
 *
 * The UPDATE is scoped with `WHERE tt.event_id = ?` so an iteration only ever
 * reclassifies its own rows and never disturbs another iteration's data.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the JOIN/CASE evaluate exactly as in production.
 *
 * **Validates: Requirements 2.9**
 */
class CapacityModeBackfillTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The exact backfill CASE expression from the migration, scoped to a single
     * event's types via a `?` placeholder so iterations stay isolated.
     */
    private const BACKFILL_SQL = <<<'SQL'
        UPDATE ticket_types tt
        JOIN events e ON e.id = tt.event_id
        SET tt.capacity_mode = CASE
            WHEN e.capacity IS NOT NULL AND tt.capacity = e.capacity THEN 'shared_pool'
            ELSE 'capped'
        END
        WHERE tt.event_id = ?
    SQL;

    /**
     * Property 5: Migration backfill classifies existing types correctly —
     * shared_pool iff the type's capacity equalled its event's non-null overall
     * capacity, else capped.
     *
     * **Validates: Requirements 2.9**
     */
    // Feature: event-experience-polish, Property 5: Migration backfill classifies existing types correctly
    public function test_migration_backfill_classifies_existing_types_correctly(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Event overall capacity: sometimes null (unlimited), else 1..500.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(1, 500),
            ),
            // A generated sequence of per-type capacities (1..500). Normalised
            // inside the body to 1..4 rows so the data stays small (each entry
            // becomes one Ticket_Type row) while the generator itself stays a
            // plain Eris sequence.
            Generator\seq(Generator\choose(1, 500)),
        )
            ->then(function (?int $eventCapacity, array $capacitySeq): void {
                // Normalise the generated sequence to 1..4 capacities. Take at
                // most four; if the sequence was empty, fall back to a single
                // capacity equal to the event capacity (or 1 when it is null)
                // so the shared_pool branch is still occasionally exercised.
                $typeCapacities = array_slice($capacitySeq, 0, 4);
                if ($typeCapacities === []) {
                    $typeCapacities = [$eventCapacity ?? 1];
                }

                // Each iteration builds its OWN Event + types and reclassifies
                // only those rows (WHERE event_id = ?), so no cross-iteration
                // cleanup is needed.
                $event = Event::factory()->create(['capacity' => $eventCapacity]);

                // Seed every type with a WRONG placeholder mode ('capped'). For
                // rows whose capacity equals a non-null event capacity, the
                // backfill must FLIP them to 'shared_pool'; for the rest it must
                // leave/set them to 'capped'. Starting everything at 'capped'
                // ensures the flip-to-shared_pool direction is actually tested.
                $types = [];
                foreach ($typeCapacities as $capacity) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'capacity' => $capacity,
                        'capacity_mode' => TicketType::MODE_CAPPED,
                    ]);
                }

                // Run the migration's EXACT backfill SQL, scoped to this event.
                DB::statement(self::BACKFILL_SQL, [$event->id]);

                foreach ($types as $type) {
                    // Read the persisted mode scope-free so it reflects the
                    // committed row exactly as the migration would leave it.
                    $mode = TicketType::withoutGlobalScopes()
                        ->whereKey($type->id)
                        ->value('capacity_mode');

                    // Independently computed oracle (does not reuse the SQL).
                    $expected = ($eventCapacity !== null && (int) $type->capacity === $eventCapacity)
                        ? TicketType::MODE_SHARED_POOL
                        : TicketType::MODE_CAPPED;

                    $this->assertSame(
                        $expected,
                        $mode,
                        sprintf(
                            'Backfill classification for (event.capacity=%s, type.capacity=%d)',
                            var_export($eventCapacity, true),
                            (int) $type->capacity,
                        )
                    );
                }
            });

        // Fixed cases guarantee coverage of each branch that random generation
        // reaches only rarely.

        // Non-null event capacity, type capacity equal → shared_pool.
        $eventEqual = Event::factory()->create(['capacity' => 100]);
        $matching = TicketType::factory()->forEvent($eventEqual)->create([
            'capacity' => 100,
            'capacity_mode' => TicketType::MODE_CAPPED,
        ]);
        $nonMatching = TicketType::factory()->forEvent($eventEqual)->create([
            'capacity' => 50,
            'capacity_mode' => TicketType::MODE_CAPPED,
        ]);
        DB::statement(self::BACKFILL_SQL, [$eventEqual->id]);
        $this->assertSame(
            TicketType::MODE_SHARED_POOL,
            TicketType::withoutGlobalScopes()->whereKey($matching->id)->value('capacity_mode'),
            'A type whose capacity equals a non-null event capacity backfills to shared_pool.'
        );
        $this->assertSame(
            TicketType::MODE_CAPPED,
            TicketType::withoutGlobalScopes()->whereKey($nonMatching->id)->value('capacity_mode'),
            'A type whose capacity differs from the event capacity stays capped.'
        );

        // Null event capacity → always capped even when the type carries a
        // capacity (NULL never equals anything under the CASE guard).
        $eventNull = Event::factory()->create(['capacity' => null]);
        $underNull = TicketType::factory()->forEvent($eventNull)->create([
            'capacity' => 100,
            'capacity_mode' => TicketType::MODE_CAPPED,
        ]);
        DB::statement(self::BACKFILL_SQL, [$eventNull->id]);
        $this->assertSame(
            TicketType::MODE_CAPPED,
            TicketType::withoutGlobalScopes()->whereKey($underNull->id)->value('capacity_mode'),
            'With a null event capacity every type backfills to capped.'
        );
    }
}
