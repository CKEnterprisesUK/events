<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a role assignment/removal/demotion would violate the four-role
 * model or the single-Owner invariant (Requirements 3.1, 3.2, 4.5). The
 * operation is rejected and no data is changed; the message names the
 * single-Owner constraint where relevant so callers can surface it.
 */
class RoleAssignmentException extends RuntimeException {}
