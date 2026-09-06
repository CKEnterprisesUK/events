<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Services\CapacityReservationService::reserve()} when a
 * requested quantity exceeds the remaining available capacity of a Ticket_Type
 * (or the Event's overall capacity). The reservation is rejected atomically —
 * nothing is reserved and all counts are left unchanged — and an error
 * indicating insufficient availability is surfaced to the caller.
 * (Requirements 6.7, 6.8, 10.6, 5.6)
 */
class InsufficientCapacityException extends RuntimeException {}
