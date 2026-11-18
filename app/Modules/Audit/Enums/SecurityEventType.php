<?php

namespace App\Modules\Audit\Enums;

enum SecurityEventType: string
{
    case LoginFailed = 'LOGIN_FAILED';
    case LoginSucceeded = 'LOGIN_SUCCEEDED';
    case RefreshTokenReuse = 'REFRESH_TOKEN_REUSE';
    case MfaFailed = 'MFA_FAILED';
    case MfaEnrolled = 'MFA_ENROLLED';
    case RecoveryCodeUsed = 'RECOVERY_CODE_USED';
    case OtpLocked = 'OTP_LOCKED';
    case PasswordReset = 'PASSWORD_RESET';
    case DeviceRevoked = 'DEVICE_REVOKED';
    case SuspendedAccountLogin = 'SUSPENDED_ACCOUNT_LOGIN';
    case ContactChanged = 'CONTACT_CHANGED';
    case AccountDeletionRequested = 'ACCOUNT_DELETION_REQUESTED';
    case AccountDeletionCancelled = 'ACCOUNT_DELETION_CANCELLED';
    case AccountAnonymized = 'ACCOUNT_ANONYMIZED';

    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::RefreshTokenReuse => Severity::High,
            self::LoginFailed, self::MfaFailed, self::OtpLocked, self::SuspendedAccountLogin, self::RecoveryCodeUsed => Severity::Warning,
            default => Severity::Info,
        };
    }
}
