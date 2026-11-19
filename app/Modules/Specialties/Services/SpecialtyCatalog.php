<?php

namespace App\Modules\Specialties\Services;

use App\Modules\Specialties\Models\Specialty;
use Illuminate\Support\Facades\Cache;

/**
 * The public specialty list, cached as `specialties:all` (CLAUDE.md §25) and
 * cleared after any change.
 */
final class SpecialtyCatalog
{
    public const CACHE_KEY = 'specialties:all';

    /** @return list<array{id: string, name: string, slug: string, synonyms: list<string>, subspecialties: list<array{id: string, name: string, slug: string}>}> */
    public function active(): array
    {
        /** @var list<array{id: string, name: string, slug: string, synonyms: list<string>, subspecialties: list<array{id: string, name: string, slug: string}>}> $list */
        $list = Cache::remember(self::CACHE_KEY, now()->addHours(6), fn () => Specialty::query()
            ->where('is_active', true)
            ->with(['subspecialties' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(fn (Specialty $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'synonyms' => $s->synonyms,
                'subspecialties' => $s->subspecialties->map(fn ($sub) => ['id' => $sub->id, 'name' => $sub->name, 'slug' => $sub->slug])->values()->all(),
            ])->values()->all());

        return $list;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
