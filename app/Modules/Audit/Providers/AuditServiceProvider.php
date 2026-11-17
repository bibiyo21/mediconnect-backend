<?php

namespace App\Modules\Audit\Providers;

use App\Modules\Audit\Console\CreateAuditPartitions;
use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Audit\Contracts\RecordsConsents;
use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Http\Middleware\RecordSuperAdminActivity;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\ConsentRecorder;
use App\Modules\Audit\Services\SecurityEventRecorder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordsSecurityEvents::class, SecurityEventRecorder::class);
        $this->app->singleton(RecordsConsents::class, ConsentRecorder::class);
        $this->app->singleton(RecordsAudit::class, AuditLogger::class);
    }

    public function boot(Kernel $kernel): void
    {
        if ($kernel instanceof HttpKernel) {
            $kernel->appendMiddlewareToGroup('api', RecordSuperAdminActivity::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateAuditPartitions::class]);
        }
    }
}
