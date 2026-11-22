<?php

namespace App\Modules\WalkIns\Estimation;

/**
 * Why an estimate is what it is. Shown to staff and used by the UI to word the estimate honestly;
 * the codes are part of the API contract once 5.5 exposes them.
 */
enum WaitBasis: string
{
    case NextInLine = 'next_in_line';
    case QueueAhead = 'queue_ahead';
    case CurrentConsultation = 'current_consultation';
    case ConsultationOverrun = 'consultation_overrun';
    case ProtectedAppointment = 'protected_appointment';
    case ProtectedHold = 'protected_hold';
    case ScheduledBreak = 'scheduled_break';
    case OffShift = 'off_shift';
    case DoctorBreak = 'doctor_break';
    case BreakOverrun = 'break_overrun';
    case DoctorUnavailable = 'doctor_unavailable';
    case DoctorNotCheckedIn = 'doctor_not_checked_in';
    case DoctorCheckedOut = 'doctor_checked_out';
    case TriageReordered = 'triage_reordered';
    case UnarrivedCounted = 'unarrived_counted';
    case DurationRolling = 'duration_rolling';
    case DurationService = 'duration_service';
    case DurationDefault = 'duration_default';
    case ClinicClosing = 'clinic_closing';
    case Unknown = 'unknown';
}
