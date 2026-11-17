<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Services\OneTimeMessageSender;
use App\Modules\Notifications\Sms\DisabledSmsGateway;
use App\Modules\Notifications\Sms\MailPreviewSmsGateway;
use Illuminate\Support\ServiceProvider;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SendsOneTimeMessages::class, OneTimeMessageSender::class);

        $this->app->singleton(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'mail-preview' => new MailPreviewSmsGateway,
            default => new DisabledSmsGateway,
        });
    }
}
