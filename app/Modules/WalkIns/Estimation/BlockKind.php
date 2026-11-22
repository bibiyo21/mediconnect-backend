<?php

namespace App\Modules\WalkIns\Estimation;

/** Why the doctor cannot take a walk-in during a blocked interval. */
enum BlockKind: string
{
    /** A live appointment (protected, CLAUDE.md §7.6). */
    case Appointment = 'APPOINTMENT';
    /** An ACTIVE slot hold: a patient is paying for the slot. */
    case Hold = 'HOLD';
    /** A break written into the schedule (for example lunch 12:00 to 13:00). */
    case ScheduledBreak = 'SCHEDULED_BREAK';
    /** A gap between shifts the doctor works at this location today. */
    case OffShift = 'OFF_SHIFT';

    public function basis(): WaitBasis
    {
        return match ($this) {
            self::Appointment => WaitBasis::ProtectedAppointment,
            self::Hold => WaitBasis::ProtectedHold,
            self::ScheduledBreak => WaitBasis::ScheduledBreak,
            self::OffShift => WaitBasis::OffShift,
        };
    }
}
