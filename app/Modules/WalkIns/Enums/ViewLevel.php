<?php

namespace App\Modules\WalkIns\Enums;

/**
 * How much of a queue a reader may see (decision 295, minimum necessary access). Staff of the location see names, and the
 * phone in the entry detail. The doctor sees names for their own queue, never phones. Global-scope holders (ADMIN, SUPPORT_AGENT)
 * see a restricted view with no guest name, phone, HMO text or note, and every read of it is audited.
 */
enum ViewLevel: string
{
    /** The patient (or guardian) who registered the entry: their own place, estimate and fee, nobody else's. */
    case Owner = 'OWNER';
    case Staff = 'STAFF';
    case Doctor = 'DOCTOR';
    case Restricted = 'RESTRICTED';
    case None = 'NONE';

    public function seesNames(): bool
    {
        return $this === self::Staff || $this === self::Doctor;
    }
}
