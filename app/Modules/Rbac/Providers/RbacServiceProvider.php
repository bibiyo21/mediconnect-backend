<?php

namespace App\Modules\Rbac\Providers;

use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Services\RoleAssignment;
use Illuminate\Support\ServiceProvider;

final class RbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AssignsRoles::class, RoleAssignment::class);
    }
}
