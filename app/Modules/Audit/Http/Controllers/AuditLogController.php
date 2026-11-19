<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /admin/audit-logs (`audit_logs.view`). Newest first, page pagination,
 * filters on action, actor, subject and time range. Changes were scrubbed of
 * secrets when written; export (`audit_logs.export`) is a later step.
 */
final class AuditLogController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'filter' => ['sometimes', 'array:action,actor_user_id,subject_type,subject_id,from,to'],
            'filter.action' => ['sometimes', 'string', 'max:100'],
            'filter.actor_user_id' => ['sometimes', 'uuid'],
            'filter.subject_type' => ['sometimes', 'string', 'max:100'],
            'filter.subject_id' => ['sometimes', 'string', 'max:64'],
            'filter.from' => ['sometimes', 'date'],
            'filter.to' => ['sometimes', 'date', 'after_or_equal:filter.from'],
        ]);
        /** @var array<string, string> $filter */
        $filter = $data['filter'] ?? [];

        $page = DB::table('audit_logs')
            ->when(isset($filter['action']), fn ($q) => $q->where('action', $filter['action']))
            ->when(isset($filter['actor_user_id']), fn ($q) => $q->where('actor_user_id', $filter['actor_user_id']))
            ->when(isset($filter['subject_type']), fn ($q) => $q->where('subject_type', $filter['subject_type']))
            ->when(isset($filter['subject_id']), fn ($q) => $q->where('subject_id', $filter['subject_id']))
            ->when(isset($filter['from']), fn ($q) => $q->where('occurred_at', '>=', CarbonImmutable::parse($filter['from'])))
            ->when(isset($filter['to']), fn ($q) => $q->where('occurred_at', '<=', CarbonImmutable::parse($filter['to'])->endOfDay()))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 50), ['id', 'occurred_at', 'actor_user_id', 'actor_roles', 'action', 'subject_type', 'subject_id', 'changes', 'is_access_event', 'request_id']);

        $items = [];
        /** @var \stdClass $row */
        foreach ($page->items() as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at)->toIso8601ZuluString(),
                'actor_user_id' => $row->actor_user_id,
                'actor_roles' => $row->actor_roles,
                'action' => (string) $row->action,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'changes' => $row->changes !== null ? json_decode((string) $row->changes, true) : null,
                'is_access_event' => (bool) $row->is_access_event,
                'request_id' => $row->request_id,
            ];
        }

        return ApiResponse::success($items, meta: [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }
}
