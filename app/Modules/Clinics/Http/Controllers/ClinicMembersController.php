<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Http\Requests\AddMemberRequest;
use App\Modules\Clinics\Http\Resources\ClinicManagementResource;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\InvalidValue;
use App\Shared\Http\ApiResponse;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clinic staff (`clinics.manage_staff`, clinic-scoped). Membership is the
 * source of truth for clinic access (D-17); the global role label
 * (CLINIC_MANAGER or CLINIC_STAFF) is added with the first membership and
 * removed with the last.
 */
final class ClinicMembersController
{
    public function __construct(
        private readonly ClinicGuard $guard,
        private readonly Accounts $accounts,
        private readonly AssignsRoles $roles,
        private readonly RecordsAudit $audit,
    ) {}

    public function store(AddMemberRequest $request, string $clinicId): JsonResponse
    {
        $locationId = $request->input('clinic_location_id');
        // A location row needs access at that location; a clinic-wide row needs clinic-wide access,
        // so a manager restricted to one location cannot widen anyone's reach (or their own).
        $clinic = is_string($locationId)
            ? $this->guard->authorizeAtLocation($request, 'clinics.manage_staff', $clinicId, $locationId)
            : $this->guard->authorizeWide($request, 'clinics.manage_staff', $clinicId);

        $user = $this->findAccount($request);
        /** @var User $actor */
        $actor = $request->user();
        if ($user->id === $actor->id) {
            throw ValidationException::withMessages(['member' => ['You cannot change your own membership. Ask another manager.']]);
        }
        $role = Role::from((string) $request->input('member_role'));

        $exists = ClinicMember::where('clinic_id', $clinic->id)->where('user_id', $user->id)->where('status', 'ACTIVE')
            ->where(fn ($q) => $locationId === null ? $q->whereNull('clinic_location_id') : $q->where('clinic_location_id', $locationId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['email' => ['This person is already a member here.']]);
        }

        DB::transaction(function () use ($clinic, $user, $role, $locationId, $actor) {
            $this->roles->assign($user, $role);
            $member = new ClinicMember;
            $member->forceFill([
                'clinic_id' => $clinic->id, 'clinic_location_id' => $locationId, 'user_id' => $user->id, 'member_role' => $role->value,
                'status' => 'ACTIVE', 'invited_by' => $actor->id, 'joined_at' => now(),
            ])->save();
            $this->audit->record('clinic.member_added', 'clinic_member', $member->id, ['user_id' => $user->id, 'member_role' => $role->value], clinicId: $clinic->id);
        });

        return ApiResponse::success(new ClinicManagementResource(ClinicManagementController::loaded($clinic), $this->guard->abilities($request, $clinic->id)), status: 201);
    }

    public function destroy(Request $request, string $clinicId, string $memberId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.manage_staff', $clinicId);
        /** @var ClinicMember $member */
        $member = ClinicMember::where('clinic_id', $clinic->id)->where('status', 'ACTIVE')->whereKey($memberId)->firstOrFail();
        /** @var User $actor */
        $actor = $request->user();

        // Same rule as adding: a location row needs access at that location, a clinic-wide row clinic-wide access.
        $member->clinic_location_id !== null
            ? $this->guard->authorizeAtLocation($request, 'clinics.manage_staff', $clinicId, $member->clinic_location_id)
            : $this->guard->authorizeWide($request, 'clinics.manage_staff', $clinicId);

        if ($member->user_id === $actor->id) {
            throw ValidationException::withMessages(['member' => ['You cannot change your own membership. Ask another manager.']]);
        }

        DB::transaction(function () use ($member, $clinic) {
            $member->forceFill(['status' => 'REMOVED', 'removed_at' => now()])->save();
            $stillHolds = ClinicMember::where('user_id', $member->user_id)->where('member_role', $member->member_role)->where('status', 'ACTIVE')->exists();
            if (! $stillHolds) {
                $this->roles->revoke($member->user, Role::from($member->member_role));
            }
            $this->audit->record('clinic.member_removed', 'clinic_member', $member->id, ['user_id' => $member->user_id], clinicId: $clinic->id);
        });

        return ApiResponse::success(new ClinicManagementResource(ClinicManagementController::loaded($clinic), $this->guard->abilities($request, $clinic->id)));
    }

    private function findAccount(AddMemberRequest $request): User
    {
        $email = $request->input('email');
        try {
            $user = is_string($email) && $email !== ''
                ? $this->accounts->findByEmail(mb_strtolower(trim($email)))
                : $this->accounts->findByMobile(PhoneNumber::parse((string) $request->input('mobile')));
        } catch (InvalidValue $e) {
            throw ValidationException::withMessages(['mobile' => [$e->getMessage()]]);
        }

        if ($user === null || ! $user->isActive()) {
            $field = is_string($email) && $email !== '' ? 'email' : 'mobile';
            throw ValidationException::withMessages([$field => ['No active MediConnect account uses this address. Ask the person to create an account first.']]);
        }

        return $user;
    }
}
