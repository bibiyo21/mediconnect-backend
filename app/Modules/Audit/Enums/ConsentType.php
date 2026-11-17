<?php

namespace App\Modules\Audit\Enums;

enum ConsentType: string
{
    case PrivacyNotice = 'PRIVACY_NOTICE';
    case Terms = 'TERMS';
    case Telemedicine = 'TELEMEDICINE';
    case Recording = 'RECORDING';
    case SelfPayFallback = 'SELF_PAY_FALLBACK';
    case GuardianAuthorization = 'GUARDIAN_AUTHORIZATION';
    case Marketing = 'MARKETING';
}
