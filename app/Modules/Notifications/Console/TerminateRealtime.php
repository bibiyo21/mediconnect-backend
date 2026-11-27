<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Notifications\Services\RealtimeSessions;
use App\Modules\Users\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator tool (runbook): closes every WebSocket of one user now, in this process. Validates the
 * UUID, records who did it and why as a security event first, then terminates. Idempotent.
 */
final class TerminateRealtime extends Command
{
    protected $signature = 'realtime:terminate {user : the user UUID} {--reason= : a ticket reference, never patient details (required)} {--operator= : who is running this (defaults to the OS user)}';

    protected $description = 'Close all realtime connections of one user (audited)';

    public function handle(RealtimeSessions $sessions, RecordsSecurityEvents $events): int
    {
        $userId = (string) $this->argument('user');
        $reason = trim((string) $this->option('reason'));
        $operator = trim((string) ($this->option('operator') ?: get_current_user()));

        if (! Str::isUuid($userId)) {
            $this->error('The user must be a UUID.');

            return self::INVALID;
        }
        if ($reason === '') {
            $this->error('--reason is required.');

            return self::INVALID;
        }

        try {
            $events->record(
                SecurityEventType::RealtimeManualTerminate,
                User::whereKey($userId)->exists() ? $userId : null,
                ['operator' => mb_substr($operator, 0, 100), 'reason' => mb_substr($reason, 0, 200), 'target_hash' => hash('sha256', $userId)],
            );
        } catch (Throwable $e) {
            // An outage that breaks the audit write is exactly when an operator needs the close: warn, then terminate.
            $this->warn('The audit event could not be recorded ('.$e::class.'); closing the connections anyway.');
            Log::warning('realtime.manual_terminate.audit_failed', ['target_hash' => hash('sha256', $userId), 'exception' => $e::class]);
        }

        if (! $sessions->terminateNow($userId, 'manual')) {
            $this->error('Reverb did not accept the call (recorded as REALTIME_TERMINATION_FAILED).');

            return self::FAILURE;
        }
        $this->info('Connections closed.');

        return self::SUCCESS;
    }
}
