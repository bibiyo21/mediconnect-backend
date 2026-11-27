<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\DTOs\AdmissionPresence;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Presence for walk-in admission (decisions 168 and 179): PostgreSQL only, never the Redis hint (a stale hint would overstate availability). */
final class PresenceAdmissionService implements PresenceAdmission
{
    public function __construct(private readonly Clock $clock) {}

    public function lockShared(string $doctorProfileId, string $locationId): AdmissionPresence
    {
        return $this->read($doctorProfileId, $locationId, 'share');
    }

    public function lockExclusive(string $doctorProfileId, string $locationId): AdmissionPresence
    {
        return $this->read($doctorProfileId, $locationId, 'update');
    }

    public function current(string $doctorProfileId, string $locationId): AdmissionPresence
    {
        return $this->read($doctorProfileId, $locationId, 'none');
    }

    /** @param  'share'|'update'|'none'  $lock */
    private function read(string $doctorProfileId, string $locationId, string $lock): AdmissionPresence
    {
        $query = DoctorPresenceSession::query()->where('doctor_profile_id', $doctorProfileId)->whereNull('checked_out_at');
        $session = match ($lock) {
            'share' => $query->sharedLock()->first(),
            'update' => $query->lockForUpdate()->first(),
            default => $query->first(),
        };

        if ($session !== null) {
            // Open at another location (or online): the doctor is not in at this one.
            if ($session->clinic_location_id !== $locationId) {
                return new AdmissionPresence(PresenceStatus::NotCheckedIn);
            }

            return new AdmissionPresence(
                $session->current_status,
                $session->current_status === PresenceStatus::OnBreak ? $session->current_break_expected_end_at : null,
                $session->id,
            );
        }

        return new AdmissionPresence($this->checkedOutHereToday($doctorProfileId, $locationId) ? PresenceStatus::Out : PresenceStatus::NotCheckedIn);
    }

    /** A session at this location closed during the location's current local day. */
    private function checkedOutHereToday(string $doctorProfileId, string $locationId): bool
    {
        $timezone = DB::table('clinic_locations')->where('id', $locationId)->value('timezone');
        $start = $this->clock->now()->setTimezone(is_string($timezone) ? $timezone : (string) config('mediconnect.timezone'))->startOfDay();

        return DoctorPresenceSession::query()
            ->where('doctor_profile_id', $doctorProfileId)->where('clinic_location_id', $locationId)
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '>=', CarbonImmutable::instance($start)->utc())
            ->where('checked_out_at', '<', CarbonImmutable::instance($start)->addDay()->utc())
            ->exists();
    }
}
