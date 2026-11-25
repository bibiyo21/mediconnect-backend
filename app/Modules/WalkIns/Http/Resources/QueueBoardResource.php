<?php

namespace App\Modules\WalkIns\Http\Resources;

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\WalkIns\DTOs\EntryView;
use App\Modules\WalkIns\Models\WalkInQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The staff board of a location for one local date. Names appear for the location's staff and the queue's doctor; global-scope readers get the
 * restricted view. Minutes appear for the next waiting entries only; the rest show their position.
 *
 * @property array{location: ClinicLocation, local_date: string, restricted: bool, queues: list<array{queue: WalkInQueue, doctor_name: string|null, live_count: int, entries: list<EntryView>}>} $resource
 */
final class QueueBoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $board = $this->resource;

        return [
            'clinic_location_id' => $board['location']->id,
            'timezone' => $board['location']->timezone,
            /** The queue's local date (Y-m-d). */
            'local_date' => $board['local_date'],
            /** True when any queue is shown in the restricted view (no names, phones, HMO text or notes). */
            'restricted' => $board['restricted'],
            'queues' => array_map(fn (array $row) => [
                ...(new QueueResource($row['queue']))->toArray($request),
                'doctor_name' => $row['doctor_name'],
                /** Entries still in the queue (REGISTERED, WAITING, CALLED, IN_CONSULTATION, SKIPPED). */
                'live_count' => $row['live_count'],
                /** In call order: in consultation and called, then waiting by the queue rule, then skipped. */
                'entries' => array_map(fn (EntryView $v) => (new WalkInEntryResource($v))->toArray($request), $row['entries']),
            ], $board['queues']),
        ];
    }
}
