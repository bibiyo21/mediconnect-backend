<?php

namespace App\Modules\WalkIns\Enums;

/** Why an entry was cancelled (decision 52). Only the code is ever stored on the event, audit row or notice. */
enum CancelReason: string
{
    case PatientRequest = 'PATIENT_REQUEST';
    case PatientLeft = 'PATIENT_LEFT';
    case DuplicateEntry = 'DUPLICATE_ENTRY';
    case Other = 'OTHER';
}
