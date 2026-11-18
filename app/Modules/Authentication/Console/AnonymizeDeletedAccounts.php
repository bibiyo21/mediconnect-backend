<?php

namespace App\Modules\Authentication\Console;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Patients\Contracts\PatientProfiles;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Anonymises accounts whose deletion grace period has ended (D-38). Runs daily;
 * safe to rerun: each account is handled once, inside its own transaction,
 * and only while the request is still pending.
 */
final class AnonymizeDeletedAccounts extends Command
{
    protected $signature = 'accounts:anonymize-deleted';

    protected $description = 'Anonymise accounts whose deletion grace period has ended';

    public function handle(Accounts $accounts, PatientProfiles $patients, RecordsSecurityEvents $events): int
    {
        $cutoff = now()->subDays((int) config('mediconnect.account_deletion_grace_days'));
        $count = 0;

        User::whereNotNull('deletion_requested_at')->whereNull('anonymized_at')->where('deletion_requested_at', '<=', $cutoff)
            ->orderBy('deletion_requested_at')
            ->each(function (User $candidate) use ($accounts, $patients, $events, $cutoff, &$count) {
                DB::transaction(function () use ($candidate, $accounts, $patients, $events, $cutoff, &$count) {
                    // Re-read under lock: a sign-in may have cancelled the request meanwhile.
                    $user = User::whereKey($candidate->id)->lockForUpdate()->first();
                    if ($user === null || $user->anonymized_at !== null || $user->deletion_requested_at === null || $user->deletion_requested_at->greaterThan($cutoff)) {
                        return;
                    }

                    $patients->anonymizeForAccount($user);
                    $accounts->anonymize($user);
                    $events->record(SecurityEventType::AccountAnonymized, $user->id);
                    $count++;
                });
            });

        $this->info("Anonymised {$count} account(s).");

        return self::SUCCESS;
    }
}
