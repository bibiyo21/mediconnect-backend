<?php

namespace App\Modules\WalkIns\Enums;

/**
 * What happens to a doctor's live walk-in entries when the doctor checks out (increment 5.8, D-D). Required: the system never picks one
 * for a person. HOLD keeps every place; NOTIFY_AND_CANCEL cancels them with the notice.
 */
enum CheckOutDisposition: string
{
    case HoldForReassignment = 'HOLD_FOR_REASSIGNMENT';
    case NotifyAndCancel = 'NOTIFY_AND_CANCEL';
}
