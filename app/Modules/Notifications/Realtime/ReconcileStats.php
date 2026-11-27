<?php

namespace App\Modules\Notifications\Realtime;

/** Counters of one `realtime:reconcile` run (ids and counts only; they are logged as the run metric). */
final class ReconcileStats
{
    public int $channels = 0;

    public int $members = 0;

    public int $users = 0;

    public int $terminated = 0;

    public int $skipped = 0;

    public int $gaps = 0;

    public int $unknownChannels = 0;

    public int $anomalies = 0;

    public bool $partial = false;

    public bool $failed = false;

    public int $durationMs = 0;

    /** @return array{channels: int, members: int, users: int, terminated: int, skipped: int, gaps: int, unknown_channels: int, anomalies: int, partial: bool, failed: bool, duration_ms: int} */
    public function toArray(): array
    {
        return [
            'channels' => $this->channels, 'members' => $this->members, 'users' => $this->users,
            'terminated' => $this->terminated, 'skipped' => $this->skipped, 'gaps' => $this->gaps,
            'unknown_channels' => $this->unknownChannels, 'anomalies' => $this->anomalies, 'partial' => $this->partial, 'failed' => $this->failed,
            'duration_ms' => $this->durationMs,
        ];
    }
}
