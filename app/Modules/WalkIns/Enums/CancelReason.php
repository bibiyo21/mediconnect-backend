<?php

namespace App\Modules\WalkIns\Enums;

/** Why an entry was cancelled (decision 52). Only the code is ever stored on the event, audit row or notice. */
enum CancelReason: string
{
    case PatientRequest = 'PATIENT_REQUEST';
    case PatientLeft = 'PATIENT_LEFT';
    case DuplicateEntry = 'DUPLICATE_ENTRY';
    case Other = 'OTHER';
    /** The doctor checked out and staff chose NOTIFY_AND_CANCEL (increment 5.8, D-D). */
    case DoctorCheckedOut = 'DOCTOR_CHECKED_OUT';
    /** The daily sweep closed a queue from an earlier day (increment 5.8, D-O): a system cancel, never CALLED or IN_CONSULTATION. */
    case EndOfDay = 'END_OF_DAY';
}
