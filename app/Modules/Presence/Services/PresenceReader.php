<?php

namespace App\Modules\Presence\Services;

use App\Modules\Consultations\Contracts\ActiveConsultations;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Enums\PublicPresenceStatus;
use App\Modules\Presence\Models\DoctorPresenceSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads presence from PostgreSQL (the truth; one indexed row through the open-session index).
 * The 24-hour Redis hint is never read here: a stale hint would overstate a doctor's availability.
 */
final class PresenceReader
{
    public function __construct(private readonly ActiveConsultations $consultations) {}

    /** The public answer is kept this long server-side (and dropped on every change); a stale one is bounded by this. */
    public const PUBLIC_TTL_SECONDS = 10;

    /**
     * The status for the doctor's own view (`effective_status`, D-H): BUSY when stored IN and a consultation is in progress, else the stored
     * status. Not stored and not cached; advisory, so transitions still read the stored status.
     */
    public function effectiveStatus(?DoctorPresenceSession $session): PresenceStatus
    {
        if ($session === null) {
            return PresenceStatus::NotCheckedIn;
        }
        if ($session->current_status === PresenceStatus::In && $this->consultations->inProgressFor($session->doctor_profile_id) !== null) {
            return PresenceStatus::Busy;
        }

        return $session->current_status;
    }

    public function openSession(string $doctorId): ?DoctorPresenceSession
    {
        return DoctorPresenceSession::where('doctor_profile_id', $doctorId)->whereNull('checked_out_at')->first();
    }

    /**
     * The whitelisted public view (phase 5 plan Q13): a status and a location id, nothing else.
     * BUSY is derived from an in-progress consultation (increment 5.7): stored IN while a consultation runs.
     *
     * The stored status is cached for 10 seconds (dropped on every change). At read time the location and
     * its clinic must still be ACTIVE, so a clinic that was just deactivated is not shown as having a doctor in.
     *
     * @return array{doctor_id: string, status: PublicPresenceStatus, clinic_location_id: string|null}
     */
    public function publicStatus(string $doctorId): array
    {
        /** @var array{status: string, clinic_location_id: string|null} $stored */
        $stored = Cache::remember(PresenceCache::publicKey($doctorId), self::PUBLIC_TTL_SECONDS, function () use ($doctorId): array {
            $session = $this->openSession($doctorId);
            $status = $session->current_status ?? PresenceStatus::NotCheckedIn;
            // Derived BUSY (D-H): stored IN plus a running consultation. Never stored; the stored status is what transitions read.
            if ($status === PresenceStatus::In && $this->consultations->inProgressFor($doctorId) !== null) {
                $status = PresenceStatus::Busy;
            }

            return ['status' => $status->value, 'clinic_location_id' => $session?->clinic_location_id];
        });

        $locationId = $stored['clinic_location_id'];
        if ($locationId !== null && ! $this->locationActive($locationId)) {
            return ['doctor_id' => $doctorId, 'status' => PublicPresenceStatus::NotIn, 'clinic_location_id' => null];
        }

        return ['doctor_id' => $doctorId, 'status' => PublicPresenceStatus::of(PresenceStatus::from($stored['status'])), 'clinic_location_id' => $locationId];
    }

    private function locationActive(string $locationId): bool
    {
        return DB::table('clinic_locations as l')->join('clinics as c', 'c.id', '=', 'l.clinic_id')->where('l.id', $locationId)->where('l.status', 'ACTIVE')->where('c.status', 'ACTIVE')->exists();
    }
}
