<?php

namespace App\Modules\Users\Providers;

use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Services\AccountService;
use Illuminate\Support\ServiceProvider;

final class UsersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Accounts::class, AccountService::class);
    }
}
