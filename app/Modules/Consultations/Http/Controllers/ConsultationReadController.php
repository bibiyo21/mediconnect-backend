<?php

namespace App\Modules\Consultations\Http\Controllers;

use App\Modules\Consultations\Enums\ConsultationView;
use App\Modules\Consultations\Http\Requests\ConsultationIndexRequest;
use App\Modules\Consultations\Http\Resources\ConsultationResource;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationReader;
use App\Modules\Consultations\Services\ConsultationScope;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /consultations and GET /consultations/{id} (api-specification.md §2.10). Callers without consultations.view (FINANCE, SUPER_ADMIN)
 * get 403. A caller with the permission but no standing at a row gets 404 on show and no row on the list. Global readers get the restricted
 * view and every read is audited.
 */
final class ConsultationReadController
{
    public function __construct(
        private readonly ConsultationScope $scope,
        private readonly ConsultationReader $reader,
    ) {}

    /**
     * List consultations the caller may read, newest first, 20 per page (cursor).
     * Filters: status, clinic_location_id, from and to (UTC instants on the creation time; to is exclusive).
     */
    public function index(ConsultationIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('consultations.view'), 403, 'You do not have access to consultations.');

        $scope = $this->scope->for($user);
        $page = $this->reader->list($scope, $request->filters());
        $rows = $page->getCollection()->map(fn (Consultation $c) => (new ConsultationResource($c, $this->scope->levelFor($scope, $c) ?? ConsultationView::Restricted))->resolve($request));

        return ApiResponse::success($rows->values()->all(), meta: [
            'per_page' => $page->perPage(), 'next_cursor' => $page->nextCursor()?->encode(), 'previous_cursor' => $page->previousCursor()?->encode(),
        ]);
    }

    /** One consultation, in the view the caller's standing gives. 404 with no standing; 403 when the permission is missing. */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('consultations.view'), 403, 'You do not have access to consultations.');

        $consultation = Consultation::query()->with('participants')->findOrFail($id);
        $scope = $this->scope->for($user);
        $view = $this->reader->viewOf($scope, $consultation);
        abort_if($view === null, 404, 'Not found.');

        return ApiResponse::success(new ConsultationResource($consultation, $view));
    }
}
