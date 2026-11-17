<?php

namespace App\Modules\Authentication\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * Authentication failures. Messages are deliberately generic: they never say
 * whether an account exists or which part of the credentials was wrong.
 */
final class AuthFailure extends DomainException
{
    /** @param array<string, list<string>> $fieldErrors */
    private function __construct(string $message, private readonly ErrorCode $errorCode, private readonly array $fieldErrors = [])
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('The credentials are incorrect.', ErrorCode::Unauthenticated);
    }

    public static function accountSuspended(): self
    {
        return new self('This account is not active. Please contact support.', ErrorCode::Forbidden);
    }

    public static function invalidCode(): self
    {
        return new self('The code is incorrect or has expired.', ErrorCode::ValidationFailed, ['code' => ['The code is incorrect or has expired.']]);
    }

    public static function invalidVerificationToken(): self
    {
        return new self('This verification has expired. Please request a new code.', ErrorCode::ValidationFailed, ['verification_token' => ['This verification has expired. Please request a new code.']]);
    }

    public static function invalidRefreshToken(): self
    {
        return new self('Your session has ended. Please sign in again.', ErrorCode::Unauthenticated);
    }

    public static function mfaRequired(): self
    {
        return new self('Set up multi-factor authentication to continue.', ErrorCode::MfaRequired);
    }

    public static function stepUpRequired(): self
    {
        return new self(ErrorCode::StepUpRequired->defaultMessage(), ErrorCode::StepUpRequired);
    }

    public static function mfaAlreadyEnabled(): self
    {
        return new self('Multi-factor authentication is already enabled.', ErrorCode::InvalidStateTransition);
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function errors(): array
    {
        return $this->fieldErrors;
    }
}
