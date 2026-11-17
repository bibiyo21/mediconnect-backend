<?php

namespace App\Modules\Rbac\Exceptions;

use App\Modules\Rbac\Enums\Role;
use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class RoleCombinationNotAllowed extends DomainException
{
    public static function between(Role $requested, Role $held): self
    {
        return new self("The {$requested->value} role cannot be combined with {$held->value}.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::ValidationFailed;
    }

    public function errors(): array
    {
        return ['role' => [$this->getMessage()]];
    }
}
