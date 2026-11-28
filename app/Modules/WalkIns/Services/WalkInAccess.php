<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Models\WalkInQueue;
use Closure;
use Spatie\Permission\Models\Role;

/**
 * Who may act on a walk-in queue (rbac-permissions.md §5):
 *  - staff: a clinic member whose clinic role holds the permission at the queue's location (scope AC). Global roles act through
 *    the read path only, with a restricted view; they get no write access to a queue;
 *  - the doctor: a user with their own doctor profile whose DOCTOR role holds the permission (scope SD) and who is the queue's
 *    doctor. The DOCTOR role is checked itself, so a patient permission held through another role never lets a doctor register guests.
 *    `walkins.register` is not granted to DOCTOR yet (A2 awaits the owner): the path is built and inert.
 */
final class WalkInAccess
{
    public function __construct(private readonly ClinicAccess $clinics) {}

    /**
     * HTTP-request scoped: answers live in the current request's attributes and must NOT be used from queue jobs or anything that runs
     * outside one request (for example the dispatch-time audience of a broadcast, 5.5a-3), where a stale answer would grant or keep access.
     * Answers are remembered for the current request only (a board asks the same question for every queue); a new request, or a test that
     * changes a role between requests, starts clean.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    private function once(string $key, Closure $compute): mixed
    {
        $request = app('request');
        $bag = (array) $request->attributes->get('walkin.access', []);
        if (! array_key_exists($key, $bag)) {
            $bag[$key] = $compute();
            $request->attributes->set('walkin.access', $bag);
        }

        return $bag[$key];
    }

    /** The doctor's own queue is theirs to act on only while they hold an ACTIVE assignment at this location (5.8 L1: an ended assignment ends access). */
    private function assignedHere(string $doctorProfileId, string $locationId): bool
    {
        return DoctorClinicAssignment::query()->where('doctor_profile_id', $doctorProfileId)->where('clinic_location_id', $locationId)->where('status', 'ACTIVE')->exists();
    }

    public function ownDoctorId(User $user): ?string
    {
        return $this->once("doctor:{$user->id}", function () use ($user): ?string {
            $id = DoctorProfile::query()->where('user_id', $user->id)->value('id');

            return is_string($id) ? $id : null;
        });
    }

    /** A clinic member whose clinic role holds the permission at the location (no global shortcut). */
    public function memberMay(User $user, string $permission, string $locationId): bool
    {
        return $this->once("member:{$user->id}:{$permission}:{$locationId}", fn () => $this->clinics->memberAllowsAtLocation($user, $permission, $locationId));
    }

    /** Whether the DOCTOR role (not another role the user holds) grants the permission. */
    public function doctorRoleHolds(User $user, string $permission): bool
    {
        return $this->once("role:{$user->id}:{$permission}", function () use ($user, $permission): bool {
            if (! $user->hasRole('DOCTOR')) {
                return false;
            }
            $role = Role::query()->where('name', 'DOCTOR')->first();

            return $role !== null && $role->permissions()->where('name', $permission)->exists();
        });
    }

    /** The channel an actor acts through at a location for a doctor: staff console, doctor app, or null (refused). */
    public function actingChannel(User $user, string $permission, string $locationId, ?string $queueDoctorId): ?RegistrationChannel
    {
        if ($this->memberMay($user, $permission, $locationId)) {
            return RegistrationChannel::StaffConsole;
        }
        if ($queueDoctorId !== null && $this->ownDoctorId($user) === $queueDoctorId && $this->doctorRoleHolds($user, $permission) && $this->assignedHere($queueDoctorId, $locationId)) {
            return RegistrationChannel::DoctorApp;
        }

        return null;
    }

    /** Judges the caller now and refuses (404 or 403, see refuse) unless they may act on the queue with the permission. */
    public function requireActing(User $user, string $permission, WalkInQueue $queue): RegistrationChannel
    {
        return $this->actingChannel($user, $permission, $queue->clinic_location_id, $queue->doctor_profile_id) ?? $this->refuse($user, $queue);
    }

    /** 404 for a caller with no standing at the queue (its existence is not revealed); 403 for one who may read it but not change it. */
    public function refuse(User $user, WalkInQueue $queue): never
    {
        $none = $this->viewLevel($user, $queue->clinic_location_id, $queue->doctor_profile_id) === ViewLevel::None;
        abort($none ? 404 : 403, $none ? 'Not found.' : 'You do not have access to this queue.');
    }

    public function viewLevel(User $user, string $locationId, ?string $queueDoctorId): ViewLevel
    {
        return $this->once("view:{$user->id}:{$locationId}:{$queueDoctorId}", fn () => $this->computeViewLevel($user, $locationId, $queueDoctorId));
    }

    private function computeViewLevel(User $user, string $locationId, ?string $queueDoctorId): ViewLevel
    {
        if ($this->memberMay($user, 'walkins.view', $locationId)) {
            return ViewLevel::Staff;
        }
        if ($queueDoctorId !== null && $this->ownDoctorId($user) === $queueDoctorId && $this->doctorRoleHolds($user, 'walkins.view') && $this->assignedHere($queueDoctorId, $locationId)) {
            return ViewLevel::Doctor;
        }
        if ($this->clinics->allowsAtLocation($user, 'walkins.view', $locationId)) {
            return ViewLevel::Restricted;
        }

        return ViewLevel::None;
    }
}
