<?php

namespace App\Modules\WalkIns\Listeners;

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Presence\Events\PresenceChanged;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Presence moved (check-in, break, unavailable, available or check-out; increment 5.8, D-M): the estimates of the doctor's queues at that location
 * for today may have changed, so every live entry gets a fan-out hint. Hints only: the patient refetches. Runs after the presence commit, so a
 * failure here is logged (ids only) and never reaches the caller or the later after-commit callbacks.
 */
final class FanOutOnPresenceChange
{
    public function __construct(
        private readonly QueueRealtime $realtime,
        private readonly Clock $clock,
    ) {}

    public function handle(PresenceChanged $event): void
    {
        // An ONLINE session has no location (D-28): nothing to fan out.
        if ($event->clinicLocationId === '') {
            return;
        }
        try {
            $this->fanOut($event);
        } catch (Throwable $e) {
            Log::warning('walkins.presence_fanout_failed', [
                'clinic_location_id' => $event->clinicLocationId,
                'doctor_profile_id' => $event->doctorProfileId,
                'exception' => $e::class,
            ]);
        }
    }

    private function fanOut(PresenceChanged $event): void
    {
        $timezone = ClinicLocation::query()->whereKey($event->clinicLocationId)->value('timezone');
        $localDate = $this->clock->now()->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : (string) config('mediconnect.timezone'))->toDateString();

        $queueIds = WalkInQueue::query()
            ->where('clinic_location_id', $event->clinicLocationId)
            ->where('doctor_profile_id', $event->doctorProfileId)
            ->whereDate('local_date', $localDate)
            ->pluck('id');

        foreach ($queueIds as $queueId) {
            // Each queue on its own: one failing hint does not skip the others.
            $this->realtime->safely((string) $queueId, fn () => $this->realtime->fanOut((string) $queueId), 'presence_fanout');
        }
    }
}
