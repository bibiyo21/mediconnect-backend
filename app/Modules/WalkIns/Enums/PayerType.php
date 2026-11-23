<?php

namespace App\Modules\WalkIns\Enums;

/**
 * Who the patient says will pay. HMO here is a claim, never a verified coverage (principle 12):
 * until Phase 7A the entry holds only the card name as typed.
 */
enum PayerType: string
{
    case SelfPay = 'SELF_PAY';
    case Hmo = 'HMO';
}
