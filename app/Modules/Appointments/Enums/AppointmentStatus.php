<?php

namespace App\Modules\Appointments\Enums;

/** Appointment lifecycle (CLAUDE.md §10.2). The transition rules arrive with the state machine in Phase 6. */
enum AppointmentStatus: string
{
    case PendingPayment = 'PENDING_PAYMENT';
    case PendingConfirmation = 'PENDING_CONFIRMATION';
    case Confirmed = 'CONFIRMED';
    case CheckedIn = 'CHECKED_IN';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
    case NoShow = 'NO_SHOW';
    case Interrupted = 'INTERRUPTED';

    /**
     * The statuses that occupy the doctor's time: exactly the predicate of the
     * appointments_doctor_no_overlap exclusion constraint (a test keeps them
     * equal). An interrupted consultation keeps its time so it can resume.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::PendingPayment, self::PendingConfirmation, self::Confirmed, self::CheckedIn, self::InProgress, self::Interrupted];
    }

    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }
}
