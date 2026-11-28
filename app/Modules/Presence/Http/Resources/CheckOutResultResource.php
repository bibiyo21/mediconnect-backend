<?php

namespace App\Modules\Presence\Http\Resources;

use App\Modules\Presence\Models\DoctorPresenceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state after a check-out: always OUT, plus what the check-out did. Counts and ids only. Written out here rather than reusing
 * PresenceStateResource, whose status text describes the open-session states and would be wrong for OUT (5.8 m8).
 *
 * @property DoctorPresenceSession $resource
 */
final class CheckOutResultResource extends JsonResource
{
    /** @param  array<string, int>  $result */
    public function __construct($resource, private readonly array $result)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            /** Always OUT: the session is closed by the check-out. */
            'status' => 'OUT',
            /** The same as status: a closed session has no derived BUSY. */
            'effective_status' => 'OUT',
            /** @var string The session that was closed. */
            'session_id' => (string) $session->id,
            /** @var string */
            'clinic_location_id' => (string) $session->clinic_location_id,
            /** @var string UTC. */
            'checked_in_at' => $session->checked_in_at->toIso8601ZuluString(),
            /** @var string|null UTC. Always null after a check-out (a break ends with the session). */
            'break_expected_end_at' => null,
            /** @var int Increases with every change. */
            'version' => (int) $session->version,
            /** @var array{queues_paused: int, entries_held: int, entries_cancelled: int, appointments_flagged: int, tasks_created: int} Counts of what the check-out did. */
            'result' => $this->result,
        ];
    }
}
