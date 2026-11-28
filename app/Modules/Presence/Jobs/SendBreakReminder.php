<?php

namespace App\Modules\Presence\Jobs;

use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Modules\WalkIns\Contracts\BreakOverrunNotices;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tells the doctor their announced break has ended (architecture.md §9.5). Queued with a delay
 * to the expected end, on the `notifications` queue. It acts only if the session is still open,
 * still ON_BREAK and still on this same break (the expected end matches): a break that ended
 * early, was restarted, or belongs to a closed session produces nothing. A repeat run of the
 * same break is dropped (a lock per break, then a done-mark set after the notice exists).
 */
final class SendBreakReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $sessionId, public readonly string $expectedEndAt)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * The doctor's reminder, and (increment 5.8, D-P) the once-per-break "running late" notice to walk-in patients still waiting in the doctor's queues.
     */
    public function handle(SendsNotifications $notifications, Clock $clock, BreakOverrunNotices $delays): void
    {
        $session = DoctorPresenceSession::whereKey($this->sessionId)->whereNull('checked_out_at')->first();
        $expected = CarbonImmutable::parse($this->expectedEndAt);
        $current = $session?->current_break_expected_end_at;

        // Only for the same, still running break: no session, not on break, no end recorded, or another end = nothing to say.
        if ($session === null || $session->current_status !== PresenceStatus::OnBreak || $current === null || $current->ne($expected)) {
            return;
        }
        // A queue that ignores the delay (the sync driver) runs this at once: not yet time.
        if ($clock->now()->lt($expected)) {
            return;
        }
        $key = "presence:break-reminder:{$this->sessionId}:{$expected->getTimestamp()}";

        // One worker at a time per break: the lock covers "check the mark, write the notice, set the mark".
        $lock = Cache::lock($key.':lock', 30);
        if (! $lock->get()) {
            return;
        }

        try {
            if (Cache::has($key)) {
                return;
            }

            $userId = DB::table('doctor_profiles')->where('id', $session->doctor_profile_id)->value('user_id');
            // The notice rows are written together or not at all; the dedupe mark is set only once they exist, so a failed run retries.
            DB::transaction(function () use ($notifications, $delays, $userId, $session, $clock): void {
                if (is_string($userId)) {
                    $notifications->notify($userId, 'presence.break_ended', 'Your break time is up', 'Your planned break has ended. Open the app to update your status.', ['clinic_location_id' => (string) $session->clinic_location_id]);
                }
                $delays->notifyWaitingPatients((string) $session->doctor_profile_id, (string) $session->clinic_location_id, $clock->now());
            });
            Cache::put($key, true, now()->addDay());
        } finally {
            $lock->release();
        }
    }
}
