<?php

namespace App\Modules\Notifications\Realtime;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L-6 (increment 5.4b): a refused channel subscription is a security signal (probing another
 * patient's or clinic's channel), so it writes a REALTIME_SUBSCRIPTION_DENIED row (WARNING) with the
 * channel name (ids only, no payload; the Audit recorder adds the hashed IP). A client retries, so one
 * row per user per channel per minute is kept; the limiter on the endpoint bounds distinct names.
 * Recording never changes the answer: a failure is logged and the caller still gets its 403.
 */
final class SubscriptionDenials
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly RecordsSecurityEvents $events) {}

    public function record(string $userId, string $channelName): void
    {
        try {
            // Add-if-absent: only the first denial in the window wins the key.
            if (! Cache::add('realtime-denied:'.hash('sha256', $userId.'|'.$channelName), 1, self::WINDOW_SECONDS)) {
                return;
            }

            $this->events->record(SecurityEventType::RealtimeSubscriptionDenied, $userId, ['channel' => $channelName]);
        } catch (Throwable $e) {
            Log::warning('realtime.denial_not_recorded', ['user_id' => $userId, 'exception' => $e::class]);
        }
    }
}
