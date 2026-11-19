<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Http\Requests\ClinicRules;
use App\Modules\Clinics\Http\Requests\CreateClinicRequest;
use App\Modules\Clinics\Http\Resources\ClinicManagementResource;
use App\Modules\Clinics\Http\Resources\ClinicSummaryResource;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\Organization;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** /admin/clinics: every clinic (global `clinics.create` holders: ADMIN, SUPER_ADMIN). */
final class AdminClinicsController
{
    public function __construct(private readonly RecordsAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:search,status'],
            'filter.search' => ['sometimes', 'string', 'min:2', 'max:100'],
            'filter.status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        $search = $data['filter']['search'] ?? null;
        $status = $data['filter']['status'] ?? null;

        $page = Clinic::query()->with(['organization', 'locations'])->withCount('locations')
            ->when($search !== null, fn ($q) => $q->where('name', 'ilike', addcslashes((string) $search, '%_\\').'%'))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::success(ClinicSummaryResource::collection($page->items()), meta: [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function store(CreateClinicRequest $request, ClinicGuard $guard): JsonResponse
    {
        $clinic = DB::transaction(function () use ($request) {
            $organization = $request->filled('organization_id')
                ? Organization::findOrFail((string) $request->input('organization_id'))
                : Organization::create([
                    'name' => trim((string) $request->input('organization.name')),
                    'legal_name' => $request->input('organization.legal_name'),
                    'organization_type' => (string) $request->input('organization.organization_type'),
                ]);

            $clinic = new Clinic(['name' => trim((string) $request->input('name')), 'description' => $request->input('description')]);
            $clinic->organization_id = $organization->id;
            $clinic->slug = $this->uniqueSlug((string) $request->input('name'));
            $clinic->save();

            if ($request->has('location')) {
                /** @var array<string, mixed> $location */
                $location = (array) $request->validated('location');
                $location['phone'] = ClinicRules::normalisePhone($location['phone'] ?? null);
                $clinic->locations()->create($location);
            }

            $this->audit->record('clinic.created', 'clinic', $clinic->id, ['name' => $clinic->name], clinicId: $clinic->id);

            return $clinic;
        });

        return ApiResponse::success(new ClinicManagementResource($this->load($clinic), $guard->abilities($request, $clinic->id)), status: 201);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;
        for ($i = 2; Clinic::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function load(Clinic $clinic): Clinic
    {
        return $clinic->load(['organization', 'locations.operatingHours', 'locations.services', 'holidays', 'members.user.profile']);
    }
}
