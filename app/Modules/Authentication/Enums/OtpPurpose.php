<?php

namespace App\Modules\Authentication\Enums;

enum OtpPurpose: string
{
    case Register = 'REGISTER';
    case VerifyEmail = 'VERIFY_EMAIL';
    case VerifyMobile = 'VERIFY_MOBILE';
    case ResetPassword = 'RESET_PASSWORD';

    /** Purposes started by a signed-in user for their own contact details. */
    public function requiresAuthentication(): bool
    {
        return in_array($this, [self::VerifyEmail, self::VerifyMobile], true);
    }
}
