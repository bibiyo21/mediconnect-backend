<?php

namespace App\Modules\Presence\Jobs;

use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\Presence\Exceptions\ActiveConsultationBlocksChange;
use App\Modules\Presence\Exceptions\InvalidPresenceTransition;
use App\Modules\Presence\Exceptions\PresenceChangeInProgress;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Modules\Presence\Services\AutoCheckOutCandidates;
use App\Modules\Presence\Services\PresenceActor;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\WalkIns\Contracts\QueueActivity;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The automatic check-out of one forgotten session (increment 5.8, D-L). It runs as the SYSTEM actor through the same PresenceCheckOut as a
 * doctor's own check-out, with reason CLINIC_CLOSED and HOLD_FOR_REASSIGNMENT: the system never cancels a patient. A session that a block
 * refuses (D-C: an unfinished consultation or a called patient) is skipped and logged as PRESENCE_AUTO_CHECKOUT_SKIPPED, with ids and the
 * reason code only, and the next run tries again. Idempotent: a session that is already closed, or was closed and reopened, is a no-op (the
 * named session id is checked again under the lock, 5.8 m7).
 *
 * A session from an earlier local day waits for an idle period (HAG-46, owner: "wait for an idle period"): if the doctor's walk-in queues at
 * the location had a queue event within the grace minutes, the check-out is skipped with reason recent_activity and retried on the next run.
 * The same-day trigger (past closing) is not gated: the clinic is closed by then.
 */
final class AutoCheckOut implements ShouldQueue
{
    use Queueable;

    /** One attempt per run: the scheduler runs again in five minutes, so a retry here would only duplicate the log. */
    public int $tries = 1;

    public function __construct(public readonly string $sessionId, public readonly string $doctorProfileId, public readonly string $locationId)
    {
        $this->onQueue('scheduling');
    }

    public function handle(PresenceCheckOut $checkOut, QueueActivity $activity, AutoCheckOutCandidates $candidates, Clock $clock): void
    {
        $session = DoctorPresenceSession::query()->whereKey($this->sessionId)->whereNull('checked_out_at')->first();
        if ($session === null) {
            return;
        }

        $now = $clock->now();
        if ($this->earlierDay($session, $now)) {
            $last = $activity->lastActivityAt($this->doctorProfileId, $this->locationId);
            $grace = $candidates->graceFor($this->doctorProfileId, $this->locationId);
            if ($last !== null && $last->gt($now->subMinutes($grace))) {
                $this->skipped('recent_activity');

                return;
            }
        }

        try {
            $checkOut->checkOut(PresenceActor::system(), $this->doctorProfileId, $this->locationId, CheckOutReason::ClinicClosed, CheckOutDisposition::HoldForReassignment, false, $this->sessionId);
        } catch (ActiveConsultationBlocksChange $e) {
            $this->skipped($e->reasonCode());
        } catch (PresenceChangeInProgress) {
            $this->skipped('change_in_progress');
        } catch (InvalidPresenceTransition) {
            // Closed by someone else between the check above and the lock: nothing to do.
        }
    }

    /** The session began on an earlier local day than now, at the location's own clock. */
    private function earlierDay(DoctorPresenceSession $session, CarbonImmutable $now): bool
    {
        $timezone = DB::table('clinic_locations')->where('id', $this->locationId)->value('timezone');
        $timezone = is_string($timezone) && $timezone !== '' ? $timezone : (string) config('mediconnect.timezone');

        return CarbonImmutable::instance($session->checked_in_at)->setTimezone($timezone)->toDateString() < $now->setTimezone($timezone)->toDateString();
    }

    private function skipped(?string $reasonCode): void
    {
        Log::info('PRESENCE_AUTO_CHECKOUT_SKIPPED', [
            'doctor_presence_session_id' => $this->sessionId,
            'doctor_profile_id' => $this->doctorProfileId,
            'clinic_location_id' => $this->locationId,
            'reason_code' => $reasonCode,
        ]);
    }
}
