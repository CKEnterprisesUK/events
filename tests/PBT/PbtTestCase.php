<?php

namespace Tests\PBT;

use Eris\TestTrait;
use Tests\TestCase;

/**
 * Base class for property-based tests.
 *
 * Property-based tests for this feature use the Eris library (never
 * hand-rolled generators) and each property runs a minimum of 100 iterations,
 * as required by the design's Testing Strategy. Concrete property tests set
 * their iteration count with `$this->forAll(...)->withMaxSize(...)` /
 * `->limitTo(self::MIN_ITERATIONS)` as appropriate.
 *
 * Tests extending this class run against the real MySQL test database so that
 * SELECT ... FOR UPDATE row locking behaves exactly as in production.
 */
abstract class PbtTestCase extends TestCase
{
    use TestTrait;

    /**
     * Minimum property-based iterations mandated by the design Testing Strategy.
     */
    public const MIN_ITERATIONS = 100;
}
