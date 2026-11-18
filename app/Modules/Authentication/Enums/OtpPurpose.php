<?php

namespace App\Modules\Authentication\Enums;

enum OtpPurpose: string
{
    case Register = 'REGISTER';
    case VerifyEmail = 'VERIFY_EMAIL';
    case VerifyMobile = 'VERIFY_MOBILE';
    case ResetPassword = 'RESET_PASSWORD';
    /** A new email address to put on the signed-in account (POST /auth/contact/otp). */
    case ChangeEmail = 'CHANGE_EMAIL';
    /** A new mobile number to put on the signed-in account (POST /auth/contact/otp). */
    case ChangeMobile = 'CHANGE_MOBILE';

    /** Purposes started by a signed-in user for their own contact details. */
    public function requiresAuthentication(): bool
    {
        return in_array($this, [self::VerifyEmail, self::VerifyMobile], true);
    }

    /** Purposes requested only through POST /auth/contact/otp, never POST /auth/otp. */
    public function isContactChange(): bool
    {
        return in_array($this, [self::ChangeEmail, self::ChangeMobile], true);
    }
}
