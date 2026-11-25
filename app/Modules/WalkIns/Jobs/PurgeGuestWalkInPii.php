<?php

namespace App\Modules\WalkIns\Jobs;

use App\Shared\Contracts\Clock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retention of guest walk-in data (A5, plan Q6, decision 103). Daily: a guest entry older than walkins.guest_pii_retention_days loses
 * its name, phone, phone hash, typed HMO card name and priority note, and gets guest_pii_purged_at. The entry itself, its queue number,
 * status, timestamps, priority code and events stay (events and audit rows never held PII). Patient-profile entries are not touched here.
 * The figure, the controller role and purging from backups are for the owner and counsel to confirm.
 */
final class PurgeGuestWalkInPii implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(Clock $clock): void
    {
        $days = max(1, (int) config('walkins.guest_pii_retention_days'));
        $cutoff = $clock->now()->subDays($days);
        $total = 0;

        do {
            $ids = DB::table('walkin_queue_entries')->whereNull('patient_profile_id')->whereNull('guest_pii_purged_at')->where('created_at', '<', $cutoff)->orderBy('created_at')->limit(1000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $total += DB::table('walkin_queue_entries')->whereIn('id', $ids)->whereNull('guest_pii_purged_at')->update([
                'guest_name_encrypted' => null, 'guest_phone_encrypted' => null, 'guest_phone_hash' => null,
                'hmo_card_name_encrypted' => null, 'triage_note_encrypted' => null,
                'guest_pii_purged_at' => $clock->now(), 'updated_at' => $clock->now(),
            ]);
        } while ($ids->count() === 1000);

        if ($total > 0) {
            Log::info('Guest walk-in data purged', ['metric' => 'WALKIN_GUEST_PII_PURGED', 'entries' => $total, 'retention_days' => $days]);
        }
    }
}
