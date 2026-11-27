<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Consultations\DTOs\ReadScope;
use App\Modules\Consultations\Enums\ConsultationView;
use App\Modules\Consultations\Models\Consultation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;

/**
 * Reads consultations for the list and the detail (brief §4 5.7-2). Lists are cursor-paginated, newest first. Every read by a global
 * reader is audited, because those readers see the restricted view of rows they do not care for (access logs, not medical content).
 */
final class ConsultationReader
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private readonly ConsultationScope $scope,
        private readonly RecordsAudit $audit,
    ) {}

    /**
     * @param  array{status: string|null, clinic_location_id: string|null, from: CarbonImmutable|null, to: CarbonImmutable|null}  $filters
     * @return CursorPaginator<int, Consultation>
     */
    public function list(ReadScope $scope, array $filters): CursorPaginator
    {
        /** @var Builder<Consultation> $query */
        $query = $this->scope->applyTo(Consultation::query(), $scope)->with('participants')
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['clinic_location_id'] !== null, fn ($q) => $q->where('clinic_location_id', $filters['clinic_location_id']))
            ->when($filters['from'] !== null, fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn ($q) => $q->where('created_at', '<', $filters['to']))
            ->orderByDesc('created_at')->orderByDesc('id');

        /** @var CursorPaginator<int, Consultation> $page */
        $page = $query->cursorPaginate(self::PAGE_SIZE);

        // Fail closed: a row this reader has no standing at is dropped from the page, never shown restricted.
        $visible = $page->getCollection()->filter(fn (Consultation $c): bool => $this->scope->levelFor($scope, $c) !== null)->values();
        $page->setCollection($visible);

        if ($scope->global) {
            $restricted = $visible->filter(fn (Consultation $c): bool => $this->scope->levelFor($scope, $c) === ConsultationView::Restricted)->count();
            $applied = array_keys(array_filter($filters, fn (mixed $v): bool => $v !== null));
            // L-4: the ids of the rows this page returned (ids only, no PHI). A page holds at most PAGE_SIZE rows, so the list stays bounded.
            $ids = $visible->map(fn (Consultation $c): string => (string) $c->id)->values()->all();
            $this->audit->record('consultations.listed', null, null, ['count' => $visible->count(), 'restricted' => $restricted, 'filters' => $applied, 'consultation_ids' => $ids], accessEvent: true);
        }

        return $page;
    }

    /** The level of one consultation for this reader, with a global reader's restricted read audited. Null when the reader has no standing. */
    public function viewOf(ReadScope $scope, Consultation $consultation): ?ConsultationView
    {
        $level = $this->scope->levelFor($scope, $consultation);
        if ($level === ConsultationView::Restricted && $scope->global) {
            $this->audit->record('consultations.viewed', 'consultation', $consultation->id, ['view' => $level->value], accessEvent: true, clinicId: $this->clinicOf($consultation));
        }

        return $level;
    }

    private function clinicOf(Consultation $consultation): ?string
    {
        $clinic = ClinicLocation::query()->whereKey($consultation->clinic_location_id)->value('clinic_id');

        return is_string($clinic) ? $clinic : null;
    }
}
