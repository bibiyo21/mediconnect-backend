<?php

namespace App\Modules\WalkIns\Jobs;

use App\Shared\Contracts\Clock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes old failed_jobs rows that are walk-in realtime hints (M-1, increment 5.7-3). A hint that failed (for example a Reverb outage)
 * carries profile-linked payloads, so it must not sit in failed_jobs. Only the allowlisted hint classes are deleted, and only when they are
 * older than the retention window. This is never a global prune: payment and other failed jobs stay for recovery (CLAUDE.md §24).
 */
final class PruneFailedHints implements ShouldQueue
{
    use Queueable;

    /** Hint classes by displayName, as Laravel writes them into failed_jobs.payload. */
    public const HINT_CLASSES = [
        'App\\Modules\\WalkIns\\Events\\WalkInEntryUpdated',
        'App\\Modules\\WalkIns\\Events\\WalkInQueueUpdated',
        'App\\Modules\\WalkIns\\Events\\WalkInRegistered',
        'App\\Modules\\WalkIns\\Jobs\\EmitEntryHints',
        'App\\Modules\\WalkIns\\Jobs\\EmitQueueUpdated',
    ];

    public const RETENTION_HOURS = 24;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(Clock $clock): void
    {
        $cutoff = $clock->now()->subHours(self::RETENTION_HOURS);

        $deleted = DB::table('failed_jobs')
            ->whereIn(DB::raw("payload::jsonb->>'displayName'"), self::HINT_CLASSES)
            ->where('failed_at', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            Log::info('walkins.failed_hints_pruned', ['count' => $deleted]);
        }
    }
}
