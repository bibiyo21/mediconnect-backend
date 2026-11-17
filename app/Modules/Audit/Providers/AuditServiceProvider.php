<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Contracts\RecordsConsents;
use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Services\ConsentRecorder;
use App\Modules\Audit\Services\SecurityEventRecorder;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordsSecurityEvents::class, SecurityEventRecorder::class);
        $this->app->singleton(RecordsConsents::class, ConsentRecorder::class);
    }
}
