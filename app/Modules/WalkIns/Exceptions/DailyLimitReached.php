<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * A remote daily limit (A7): 409, not 429, because 429 means "retry later" and clients retry it. reason_code is the limit kind
 * (`live_entries_per_patient`, `patients_per_account_per_day`); errors.limit is the value, which the patient may see.
 */
final class DailyLimitReached extends DomainException
{
    public function __construct(public readonly string $kind, public readonly int $limit)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return false;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DailyLimitReached;
    }

    public function reasonCode(): string
    {
        return $this->kind;
    }

    public function errors(): array
    {
        return ['limit' => [(string) $this->limit]];
    }
}
