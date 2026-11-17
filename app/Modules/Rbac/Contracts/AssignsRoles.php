<?php

namespace App\Modules\Rbac\Contracts;

use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;

interface AssignsRoles
{
    public function assign(User $user, Role $role): void;

    public function revoke(User $user, Role $role): void;
}
