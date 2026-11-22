<?php

namespace App\Modules\Consultations\Enums;

/** Consultation lifecycle (database-schema.md §10). */
enum ConsultationStatus: string
{
    case Scheduled = 'SCHEDULED';
    case WaitingRoom = 'WAITING_ROOM';
    case InProgress = 'IN_PROGRESS';
    case Interrupted = 'INTERRUPTED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
