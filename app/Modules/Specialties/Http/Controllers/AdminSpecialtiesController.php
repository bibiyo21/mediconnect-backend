<?php

namespace App\Modules\Specialties\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Specialties\Http\Requests\SaveSpecialtyRequest;
use App\Modules\Specialties\Http\Requests\SaveSubspecialtyRequest;
use App\Modules\Specialties\Http\Resources\AdminSpecialtyResource;
use App\Modules\Specialties\Models\Specialty;
use App\Modules\Specialties\Models\Subspecialty;
use App\Modules\Specialties\Services\SpecialtyCatalog;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * /admin/specialties (`specialties.manage`, D-39). Entries are deactivated,
 * never deleted, because doctors will reference them. Every change is audited
 * and clears the public cache.
 */
final class AdminSpecialtiesController
{
    public function __construct(
        private readonly SpecialtyCatalog $catalog,
        private readonly RecordsAudit $audit,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(AdminSpecialtyResource::collection(Specialty::with('subspecialties')->orderBy('name')->get()));
    }

    public function store(SaveSpecialtyRequest $request): JsonResponse
    {
        $specialty = DB::transaction(function () use ($request) {
            $specialty = new Specialty([
                'name' => trim((string) $request->input('name')),
                'slug' => $this->uniqueSlug((string) $request->input('name')),
                'synonyms' => $this->synonyms($request),
                'is_active' => $request->boolean('is_active', true),
            ]);
            // Created by a MEDICAL_ADMIN in the console: reviewed by definition.
            $specialty->review_status = 'REVIEWED';
            $specialty->save();
            $this->audit->record('specialty.created', 'specialty', $specialty->id, ['name' => $specialty->name]);

            return $specialty;
        });
        $this->catalog->forget();

        return ApiResponse::success(new AdminSpecialtyResource($specialty->load('subspecialties')), status: 201);
    }

    public function update(SaveSpecialtyRequest $request, string $specialtyId): JsonResponse
    {
        $specialty = Specialty::findOrFail($specialtyId);

        DB::transaction(function () use ($request, $specialty) {
            if ($request->has('name')) {
                $specialty->name = trim((string) $request->input('name'));
            }
            if ($request->has('synonyms')) {
                $specialty->synonyms = $this->synonyms($request);
            }
            if ($request->has('is_active')) {
                $specialty->is_active = $request->boolean('is_active');
            }
            if ($request->boolean('reviewed')) {
                $specialty->review_status = 'REVIEWED';
            }
            $changed = array_keys($specialty->getDirty());
            $specialty->save();
            if ($changed !== []) {
                $this->audit->record('specialty.updated', 'specialty', $specialty->id, ['fields' => $changed]);
            }
        });
        $this->catalog->forget();

        return ApiResponse::success(new AdminSpecialtyResource($specialty->load('subspecialties')));
    }

    public function storeSubspecialty(SaveSubspecialtyRequest $request, string $specialtyId): JsonResponse
    {
        $specialty = Specialty::findOrFail($specialtyId);
        $name = trim((string) $request->input('name'));
        $this->assertUniqueName($specialty, $name);

        $sub = new Subspecialty(['name' => $name, 'slug' => Str::slug($name), 'is_active' => $request->boolean('is_active', true)]);
        $sub->specialty()->associate($specialty);
        $sub->save();
        $this->audit->record('subspecialty.created', 'subspecialty', $sub->id, ['specialty_id' => $specialty->id, 'name' => $name]);
        $this->catalog->forget();

        return ApiResponse::success(new AdminSpecialtyResource($specialty->load('subspecialties')), status: 201);
    }

    public function updateSubspecialty(SaveSubspecialtyRequest $request, string $subspecialtyId): JsonResponse
    {
        $sub = Subspecialty::with('specialty')->findOrFail($subspecialtyId);

        if ($request->has('name')) {
            $name = trim((string) $request->input('name'));
            $this->assertUniqueName($sub->specialty, $name, $sub->id);
            $sub->name = $name;
            $sub->slug = Str::slug($name);
        }
        if ($request->has('is_active')) {
            $sub->is_active = $request->boolean('is_active');
        }
        $changed = array_keys($sub->getDirty());
        $sub->save();
        if ($changed !== []) {
            $this->audit->record('subspecialty.updated', 'subspecialty', $sub->id, ['fields' => $changed]);
        }
        $this->catalog->forget();

        return ApiResponse::success(new AdminSpecialtyResource($sub->specialty->load('subspecialties')));
    }

    /** @return list<string> */
    private function synonyms(SaveSpecialtyRequest $request): array
    {
        return array_values(array_unique(array_map(fn ($s) => trim((string) $s), (array) $request->input('synonyms', []))));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        for ($i = 2; Specialty::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function assertUniqueName(Specialty $specialty, string $name, ?string $ignoreId = null): void
    {
        $exists = $specialty->subspecialties()->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => ['This specialty already has a subspecialty with that name.']]);
        }
    }
}
