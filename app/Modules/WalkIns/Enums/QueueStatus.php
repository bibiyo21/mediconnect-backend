<?php

namespace App\Modules\WalkIns\Enums;

/** Whether a walk-in queue admits registrations (database-schema.md §8). */
enum QueueStatus: string
{
    case Open = 'OPEN';
    case Paused = 'PAUSED';
    case Closed = 'CLOSED';
}
