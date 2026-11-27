<?php

namespace App\Shared\Enums;

/**
 * Stable machine-readable error codes (api-specification.md §1.3).
 * Clients branch on these, never on messages.
 */
enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case MfaRequired = 'MFA_REQUIRED';
    case StepUpRequired = 'STEP_UP_REQUIRED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case SlotUnavailable = 'SLOT_UNAVAILABLE';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case ConcurrentModification = 'CONCURRENT_MODIFICATION';
    case IdempotencyInProgress = 'IDEMPOTENCY_IN_PROGRESS';
    case ActiveConsultation = 'ACTIVE_CONSULTATION';
    case QueueClosed = 'QUEUE_CLOSED';
    case QueueFull = 'QUEUE_FULL';
    case QueueNotStarted = 'QUEUE_NOT_STARTED';
    case RegistrationNotAllowed = 'REGISTRATION_NOT_ALLOWED';
    case CapacityOverrideRequired = 'CAPACITY_OVERRIDE_REQUIRED';
    case EntryAlreadyLive = 'ENTRY_ALREADY_LIVE';
    case DailyLimitReached = 'DAILY_LIMIT_REACHED';
    case AppointmentWindowProtected = 'APPOINTMENT_WINDOW_PROTECTED';
    case DoctorNotAvailable = 'DOCTOR_NOT_AVAILABLE';
    case EntryAlreadyCalled = 'ENTRY_ALREADY_CALLED';
    case DoctorAlreadyCalling = 'DOCTOR_ALREADY_CALLING';
    case NotNextInOrder = 'NOT_NEXT_IN_ORDER';
    case RecallLimitReached = 'RECALL_LIMIT_REACHED';
    case QueueEmpty = 'QUEUE_EMPTY';
    case LateRiskAckRequired = 'LATE_RISK_ACK_REQUIRED';
    case ValidationFailed = 'VALIDATION_FAILED';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case HmoNotAccepted = 'HMO_NOT_ACCEPTED';
    case RecordFinalized = 'RECORD_FINALIZED';
    case RateLimited = 'RATE_LIMITED';
    case ServerError = 'SERVER_ERROR';
    case DependencyUnavailable = 'DEPENDENCY_UNAVAILABLE';

    public function httpStatus(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated, self::MfaRequired, self::StepUpRequired => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::SlotUnavailable, self::InvalidStateTransition, self::ConcurrentModification,
            self::IdempotencyInProgress, self::ActiveConsultation, self::QueueClosed, self::QueueFull,
            self::QueueNotStarted, self::RegistrationNotAllowed, self::CapacityOverrideRequired, self::EntryAlreadyLive,
            self::DailyLimitReached, self::LateRiskAckRequired, self::AppointmentWindowProtected, self::DoctorNotAvailable,
            self::EntryAlreadyCalled, self::DoctorAlreadyCalling, self::NotNextInOrder, self::RecallLimitReached, self::QueueEmpty => 409,
            self::ValidationFailed, self::IdempotencyKeyReused, self::HmoNotAccepted => 422,
            self::RecordFinalized => 423,
            self::RateLimited => 429,
            self::ServerError => 500,
            self::DependencyUnavailable => 503,
        };
    }

    /**
     * Safe default message for clients. Domain exceptions may supply a more
     * specific one; internal details never reach the response.
     */
    public function defaultMessage(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be understood.',
            self::Unauthenticated => 'Authentication is required.',
            self::MfaRequired => 'Multi-factor authentication is required.',
            self::StepUpRequired => 'Please verify your identity again to continue.',
            self::Forbidden => 'You are not allowed to perform this action.',
            self::NotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'This method is not allowed for this resource.',
            self::SlotUnavailable => 'This time slot is no longer available.',
            self::InvalidStateTransition => 'This action is not allowed in the current state.',
            self::ConcurrentModification => 'This record was changed by someone else. Reload and try again.',
            self::IdempotencyInProgress => 'An identical request is still being processed.',
            self::ActiveConsultation => 'End the active consultation first.',
            self::QueueClosed => 'This queue is closed.',
            self::QueueFull => 'This queue is full.',
            self::QueueNotStarted => 'This queue has not started yet. The doctor has not checked in at this clinic.',
            self::RegistrationNotAllowed => 'Registration is not available for this queue right now.',
            self::CapacityOverrideRequired => 'This queue is over its planned capacity. Confirm to add the patient anyway.',
            self::EntryAlreadyLive => 'This patient already has a place in this queue.',
            self::AppointmentWindowProtected => 'An appointment or a held slot is protected at this time. The patient cannot be called now.',
            self::DoctorNotAvailable => 'The doctor is not available to see a patient right now.',
            self::EntryAlreadyCalled => 'A patient has already been called in this queue.',
            self::DoctorAlreadyCalling => 'This doctor already has a patient called in another queue.',
            self::NotNextInOrder => 'This patient is not next in the queue order.',
            self::RecallLimitReached => 'The recall limit for this patient has been reached.',
            self::QueueEmpty => 'No patient is waiting in this queue.',
            self::DailyLimitReached => 'The daily limit for joining walk-in queues from the app has been reached.',
            self::LateRiskAckRequired => 'The clinic may not be able to see you today. Confirm that you understand before joining.',
            self::ValidationFailed => 'Validation failed.',
            self::IdempotencyKeyReused => 'This idempotency key was already used with a different request.',
            self::HmoNotAccepted => 'This HMO is not accepted for this service.',
            self::RecordFinalized => 'This record is finalized. Submit an amendment instead.',
            self::RateLimited => 'Too many requests. Please try again later.',
            self::ServerError => 'Something went wrong. Please try again.',
            self::DependencyUnavailable => 'A required service is temporarily unavailable. Please try again.',
        };
    }
}
