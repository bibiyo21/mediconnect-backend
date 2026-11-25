<?php

namespace App\Modules\Clinics\Enums;

/** How a doctor's walk-in queue capacity at a location is decided (B9): from the schedule, or no cap with the cutoff only. */
enum CapacityMode: string
{
    /** Capacity comes from the schedule (walkin_capacity, else window minutes over slot length). */
    case Derived = 'DERIVED';
    /** No cap: only the cutoff stops registrations. Stored on the queue as a null capacity. */
    case Unlimited = 'UNLIMITED';
}
