<?php

namespace App\Modules\Discovery\Providers;

use App\Modules\Discovery\Console\InstallSearchCommand;
use App\Modules\Discovery\Console\SearchStatusCommand;
use Illuminate\Support\ServiceProvider;

/** Doctor discovery and search (CLAUDE.md §3.1 module 7, Phase 3). */
final class DiscoveryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallSearchCommand::class, SearchStatusCommand::class]);
        }
    }
}
