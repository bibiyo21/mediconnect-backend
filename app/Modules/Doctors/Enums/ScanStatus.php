<?php

namespace App\Modules\Doctors\Enums;

/**
 * Malware scan state of an uploaded document (D-07). Only CLEAN files can be
 * downloaded. SCAN_FAILED (proposed addition) means no verdict after every
 * retry: the file stays blocked and the doctor is asked to upload it again.
 */
enum ScanStatus: string
{
    case PendingScan = 'PENDING_SCAN';
    case Clean = 'CLEAN';
    case Quarantined = 'QUARANTINED';
    case ScanFailed = 'SCAN_FAILED';
}
