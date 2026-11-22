<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Http\Requests\ListUsersRequest;
use App\Modules\Administration\Http\Requests\SuspendUserRequest;
use App\Modules\Administration\Http\Requests\UpdateUserRolesRequest;
use App\Modules\Administration\Http\Resources\AdminUserResource;
use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Authentication\Contracts\RevokesSessions;
use App\Modules\Notifications\Contracts\TerminatesRealtimeSessions;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * /admin/users (api-specification.md §2.15). Reads go through the Users
 * models (read-only); changes go through the owning modules' contracts.
 */
final class AdminUsersController
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly AssignsRoles $roles,
        private readonly RevokesSessions $sessions,
        private readonly RecordsAudit $audit,
        private readonly TerminatesRealtimeSessions $realtime,
    ) {}

    public function index(ListUsersRequest $request): JsonResponse
    {
        /** @var array{search?: string, status?: string, role?: string} $filter */
        $filter = (array) $request->validated('filter', []);
        $search = $filter['search'] ?? null;
        $status = $filter['status'] ?? null;
        $role = $filter['role'] ?? null;

        $page = $this->query()
            ->when($search !== null, function (Builder $q) use ($search) {
                $term = mb_strtolower(trim((string) $search));
                $digits = preg_replace('/\D/', '', $term) ?? '';
                $q->where(fn (Builder $w) => $w->where('email', 'like', addcslashes($term, '%_\\').'%')
                    ->when(strlen($digits) >= 4, fn (Builder $m) => $m->orWhere('mobile_e164', 'like', '%'.$digits)));
            })
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($role !== null, fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $role)))
            ->orderByDesc('created_at')
            ->paginate((int) $request->validated('per_page', 25));

        return ApiResponse::success(AdminUserResource::collection($page->items()), meta: [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

    public function show(string $userId): JsonResponse
    {
        return ApiResponse::success(new AdminUserResource($this->query()->findOrFail($userId)));
    }

    public function suspend(SuspendUserRequest $request, string $userId): JsonResponse
    {
        $target = User::findOrFail($userId);
        $this->guardTarget($request, $target);

        if ($target->status !== UserStatus::Suspended) {
            DB::transaction(function () use ($target, $request) {
                $this->accounts->suspend($target);
                $this->audit->record('user.suspended', 'user', $target->id, ['reason' => (string) $request->input('reason')]);
            });
            $this->sessions->revokeEverything($target);
        }

        return ApiResponse::success(new AdminUserResource($this->query()->findOrFail($userId)), message: 'The account was suspended and signed out everywhere.');
    }

    public function reactivate(SuspendUserRequest $request, string $userId): JsonResponse
    {
        $target = User::findOrFail($userId);
        $this->guardTarget($request, $target);

        if ($target->status === UserStatus::Suspended) {
            DB::transaction(function () use ($target, $request) {
                $this->accounts->reactivate($target);
                $this->audit->record('user.reactivated', 'user', $target->id, ['reason' => (string) $request->input('reason')]);
            });
        } elseif ($target->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['status' => ['Only suspended accounts can be reactivated.']]);
        }

        return ApiResponse::success(new AdminUserResource($this->query()->findOrFail($userId)), message: 'The account was reactivated.');
    }

    public function updateRoles(UpdateUserRolesRequest $request, string $userId): JsonResponse
    {
        $target = User::findOrFail($userId);
        $this->guardTarget($request, $target);

        $wanted = collect($request->roles())->map->value;
        $held = $target->getRoleNames();

        // Never leave the platform without a SUPER_ADMIN.
        if ($held->contains(Role::SuperAdmin->value) && ! $wanted->contains(Role::SuperAdmin->value)
            && User::role(Role::SuperAdmin->value)->count() <= 1) {
            throw ValidationException::withMessages(['roles' => ['This is the last SUPER_ADMIN account; add another before removing this role.']]);
        }

        DB::transaction(function () use ($target, $wanted, $held, $request) {
            foreach ($held->diff($wanted) as $role) {
                $this->roles->revoke($target, Role::from($role));
            }
            foreach ($wanted->diff($held) as $role) {
                $this->roles->assign($target, Role::from($role));
            }
            $this->audit->record('user.roles_changed', 'user', $target->id, [
                'from' => $held->values()->all(), 'to' => $wanted->values()->all(), 'reason' => (string) $request->input('reason'),
            ]);
            if ($held->diff($wanted)->isNotEmpty()) {
                $this->realtime->terminateForUser($target->id, 'role_removed'); // M-3: a lost role must not keep its channels
            }
        });

        return ApiResponse::success(new AdminUserResource($this->query()->findOrFail($userId)), message: 'Roles updated.');
    }

    /** @return Builder<User> */
    private function query(): Builder
    {
        return User::query()->with(['profile', 'roles'])
            ->addSelect(['mfa_enrolled' => DB::table('mfa_factors')->selectRaw('count(*) > 0')->whereColumn('mfa_factors.user_id', 'users.id')->whereNotNull('confirmed_at')]);
    }

    /** No admin acts on their own account here, and only a SUPER_ADMIN acts on another SUPER_ADMIN. */
    private function guardTarget(Request $request, User $target): void
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->id === $target->id) {
            throw ValidationException::withMessages(['user' => ['You cannot change your own account here.']]);
        }

        if ($target->hasRole(Role::SuperAdmin->value) && ! $actor->hasRole(Role::SuperAdmin->value)) {
            abort(403, 'Only a SUPER_ADMIN can change another SUPER_ADMIN account.');
        }
    }
}
