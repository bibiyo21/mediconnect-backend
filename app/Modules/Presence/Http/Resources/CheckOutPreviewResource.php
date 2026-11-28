<?php

namespace App\Modules\Presence\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The check-out preview: an explicit whitelist. Each key is written out here, with its shape as a docblock type, so a field added to the
 * service never reaches the client by accident (minimum necessary, increment 5.8 D-I) and OpenAPI gets the real types (5.8 M3). No names,
 * phone numbers, patient ids, reasons or notes.
 *
 * @property array<string, mixed> $resource
 */
final class CheckOutPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $preview = $this->resource;

        return [
            /** @var array{id: string, status: string, version: int}|null The open session at the location, or null when there is none. */
            'session' => $preview['session'],
            /** @var list<array{code: string, reason_code: string}> What would refuse the check-out. Empty when nothing blocks it. */
            'blocking' => $preview['blocking'],
            /** @var array{id: string|null, status: string}|null The doctor's unfinished consultation. The id is null unless the reader may see that consultation. */
            'active_consultation' => $preview['active_consultation'],
            /** @var list<array{queue_id: string, status: string, counts: array{registered: int, waiting: int, called: int, skipped: int, in_consultation: int}, guest_count: int}> The doctor's own queues for the day. */
            'queues' => $preview['queues'],
            /** @var list<string>|null CALLED or IN_CONSULTATION entry ids. Null unless the reader may see walk-in entries at the location. */
            'called_entry_ids' => $preview['called_entry_ids'],
            /** @var list<string>|null Entry ids a disposition would decide. Null unless the reader may see walk-in entries at the location. */
            'live_entry_ids' => $preview['live_entry_ids'],
            /** @var array{count: int, items: list<array{id: string, starts_at: string}>} The day's live appointments: ids and start times only. */
            'appointments' => $preview['appointments'],
        ];
    }
}
