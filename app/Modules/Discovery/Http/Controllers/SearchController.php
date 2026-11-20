<?php

namespace App\Modules\Discovery\Http\Controllers;

use App\Modules\Discovery\Http\Requests\SearchClinicsRequest;
use App\Modules\Discovery\Http\Requests\SearchDoctorsRequest;
use App\Modules\Discovery\Http\Requests\SuggestSpecialtiesRequest;
use App\Modules\Discovery\Http\Resources\ClinicSearchResultResource;
use App\Modules\Discovery\Http\Resources\DoctorSearchResultResource;
use App\Modules\Discovery\Http\Resources\SpecialtySuggestionResource;
use App\Modules\Discovery\Services\DirectorySearch;
use App\Modules\Discovery\Services\DoctorSearch;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Public search (architecture.md §12, increment 3.3). `meta` says how the
 * answer was produced: `degraded` means the PostgreSQL fallback answered
 * (no typo tolerance, facets or distance). Results are advisory (§11.6).
 */
final class SearchController
{
    public function doctors(SearchDoctorsRequest $request, DoctorSearch $search): JsonResponse
    {
        $started = hrtime(true);
        $criteria = $request->safe()->except(['cursor']);
        $result = $search->search([...$criteria, 'page' => self::page($request->validated('cursor'))]);

        return ApiResponse::success(DoctorSearchResultResource::collection($result['results']), meta: [
            ...self::meta($result, $started),
            /** @var array<string, array<string, int>> */
            'facets' => $result['facets'],
        ]);
    }

    public function clinics(SearchClinicsRequest $request, DirectorySearch $search): JsonResponse
    {
        $started = hrtime(true);
        $result = $search->clinics([...$request->safe()->except(['cursor']), 'page' => self::page($request->validated('cursor'))]);

        return ApiResponse::success(ClinicSearchResultResource::collection($result['results']), meta: self::meta($result, $started));
    }

    public function specialties(SuggestSpecialtiesRequest $request, DirectorySearch $search): JsonResponse
    {
        $started = hrtime(true);
        $result = $search->specialties($request->validated('q'), (int) $request->validated('limit', 10));

        return ApiResponse::success(SpecialtySuggestionResource::collection($result['results']), meta: self::meta($result, $started));
    }

    /**
     * @param  array{found: int, page: int, per_page: int, degraded: bool, cache_hit: bool}  $result
     * @return array{found: int, next_cursor: string|null, took_ms: int, cache_hit: bool, degraded: bool}
     */
    private static function meta(array $result, int|float $started): array
    {
        $more = $result['found'] > $result['page'] * $result['per_page'];

        return [
            'found' => $result['found'],
            'next_cursor' => $more ? rtrim(strtr(base64_encode((string) json_encode(['p' => $result['page'] + 1])), '+/', '-_'), '=') : null,
            'took_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'cache_hit' => $result['cache_hit'],
            'degraded' => $result['degraded'],
        ];
    }

    private static function page(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 1;
        }
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        $page = is_array($decoded) ? ($decoded['p'] ?? null) : null;
        if (! is_int($page) || $page < 1 || $page > 250) {
            throw ValidationException::withMessages(['cursor' => ['This cursor is not valid. Start the search again.']]);
        }

        return $page;
    }
}
