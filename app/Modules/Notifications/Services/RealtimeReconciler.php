<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Contracts\LiveSessions;
use App\Modules\Notifications\Exceptions\RealtimeReconcileFailedException;
use App\Modules\Notifications\Realtime\ChannelAuthorizer;
use App\Modules\Notifications\Realtime\ChannelKind;
use App\Modules\Notifications\Realtime\ChannelRef;
use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\ReconcileStats;
use App\Modules\Users\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * The realtime reconciliation sweep (increment 5.6-0). Once a minute it lists the occupied presence
 * channels and their members from Reverb's signed HTTP API, re-checks every (user, channel) pair
 * with the same authorization the subscription used, checks that each user still holds a live
 * credential, and closes the connections of every user that fails. It bounds the residual risks
 * of RealtimeSessions (a signature that never expires, an authorization that ended by time) and
 * catches any revocation path that forgot to call terminateForUser.
 *
 * Posture:
 * - It closes sockets ITSELF, in the scheduler process (RealtimeSessions::terminateNow), not through
 *   the queue, so a stopped worker cannot delay it. A failed call is recorded and not counted.
 * - It terminates only on a DEFINITE negative answer. Any infrastructure error (Reverb API failure,
 *   a database error while looking users up or authorizing) skips that channel or pair and is
 *   counted: failing open is deliberate, because closing every socket during an outage would turn
 *   it into a self-inflicted one. The price is that a skipped run protects nobody, so a run is
 *   DEGRADED (no `last_ok` heartbeat) whenever anything was skipped or uncovered, and three
 *   consecutive degraded or failed runs raise REALTIME_RECONCILE_FAILED (High) and a Sentry report.
 * - It validates at USER level: Reverb cannot map a socket to a token, so a user with any live
 *   credential keeps every socket. A device revoked while the owner stays signed in elsewhere is
 *   covered by the terminateForUser trigger only; a dormant socket holding a pre-fetched signature
 *   can still subscribe and receive that user's authorized hints. That is why no channel may ever
 *   carry names, personal data or clinical content (RealtimeEvent).
 * - Single Reverb node only (RealtimeConfigGuard refuses scaling in production). With several nodes
 *   a members call can 404 for a channel another node holds; that counts as a coverage gap.
 * - It takes no application row locks and runs as a command, so every tick starts with no stale
 *   model or permission state.
 *
 * Logs and events carry ids, counts and reason labels only. Runbook: docs/operations/realtime-runbook.md.
 */
final class RealtimeReconciler
{
    public const HEARTBEAT_KEY = 'realtime:sweep:last_ok';

    public const LAST_RUN_KEY = 'realtime:sweep:last_run';

    private const OFFSET_KEY = 'realtime:sweep:offset';

    /** Consecutive runs whose Reverb list call failed. */
    private const FAILURES_KEY = 'realtime:sweep:failures';

    /** Consecutive runs that were degraded: lookup failure, skipped pairs, or coverage gaps above the threshold (not a partial run). */
    private const DEGRADED_KEY = 'realtime:sweep:degraded';

    private const RUNS_KEY = 'realtime:sweep:runs';

    /** Every Nth run also lists the members of personal channels, which otherwise are trusted to be the owner. */
    private const DEEP_EVERY = 10;

    private const CHUNK = 1000;

    public function __construct(
        private readonly BroadcastManager $broadcasting,
        private readonly ChannelAuthorizer $authorizer,
        private readonly LiveSessions $liveSessions,
        private readonly RealtimeSessions $sessions,
        private readonly RecordsSecurityEvents $events,
    ) {}

    /** @return array{channels: int, members: int, users: int, terminated: int, skipped: int, gaps: int, unknown_channels: int, anomalies: int, partial: bool, failed: bool, duration_ms: int} */
    public function run(): array
    {
        $started = hrtime(true);
        $stats = new ReconcileStats;

        $broadcaster = $this->broadcasting->connection();
        if (! $broadcaster instanceof PusherBroadcaster) {
            return $stats->toArray(); // null and log drivers hold no connections
        }
        $pusher = $broadcaster->getPusher();

        app(PermissionRegistrar::class)->forgetCachedPermissions(); // no stale permission memo in this process

        $occupied = $this->occupiedChannels($pusher);
        if ($occupied === null) {
            $stats->failed = true;
            $stats->durationMs = $this->elapsedMs($started);
            $this->finish($stats, lookupFailed: false);

            return $stats->toArray();
        }
        Cache::forget(self::FAILURES_KEY);

        $deep = $this->deepRun();

        /** @var array<string, list<ChannelRef>> $pairs user id => channels the user is a member of */
        $pairs = [];
        $names = array_keys($occupied);
        sort($names);
        $total = count($names);
        $offset = $total > 0 ? ((int) Cache::get(self::OFFSET_KEY, 0)) % $total : 0;
        $ordered = array_merge(array_slice($names, $offset), array_slice($names, 0, $offset));
        $budgetMs = max(0, (int) config('realtime.reconcile.budget_seconds')) * 1000;
        $processed = 0;

        foreach ($ordered as $name) {
            if ($processed > 0 && $this->elapsedMs($started) >= $budgetMs) {
                $stats->partial = true;
                break;
            }
            $processed++;
            $stats->channels++;

            $ref = Channels::parse($name);
            if ($ref === null) {
                // Only registered patterns can ever authorize, so this was never one of ours; not terminated.
                $stats->unknownChannels++;
                Log::warning('realtime.reconcile.unknown_channel', ['channel_hash' => hash('sha256', $name)]);

                continue;
            }

            $userCount = $occupied[$name];
            if ($ref->kind === ChannelKind::User) {
                if ($userCount > 1) {
                    // More than one user on a personal channel: always alert, whether or not the members call works.
                    $this->anomaly($ref, $userCount, $stats);
                } elseif (! $deep) {
                    $pairs[$ref->id][] = $ref; // by construction the one member is the owner; no members call
                    $stats->members++;

                    continue;
                }
            }

            foreach ($this->members($pusher, $name, $stats) as $userId) {
                $pairs[$userId][] = $ref;
                $stats->members++;
            }
        }

        $lookupFailed = $this->evaluate($pairs, $stats);

        Cache::put(self::OFFSET_KEY, $stats->partial ? ($offset + $processed) % max($total, 1) : 0, 86400);
        $stats->durationMs = $this->elapsedMs($started);
        $this->finish($stats, $lookupFailed);

        Log::info('realtime.reconcile', ['metric' => 'REALTIME_RECONCILE'] + $stats->toArray());

        return $stats->toArray();
    }

    private function deepRun(): bool
    {
        try {
            Cache::add(self::RUNS_KEY, 0, 86400);

            return ((int) Cache::increment(self::RUNS_KEY)) % self::DEEP_EVERY === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Occupied presence channels as name => user_count; null when Reverb could not be asked.
     * Uses the associative call: Pusher::getChannels() would raise a TypeError on the empty list Reverb answers.
     *
     * @return array<string, int>|null
     */
    private function occupiedChannels(Pusher $pusher): ?array
    {
        try {
            $result = $pusher->get('/channels', ['filter_by_prefix' => 'presence-', 'info' => 'user_count'], true);
        } catch (Throwable $e) {
            $this->listFailed($e);

            return null;
        }

        $channels = is_array($result) && is_array($result['channels'] ?? null) ? $result['channels'] : [];
        $out = [];
        foreach ($channels as $name => $info) {
            $out[(string) $name] = is_array($info) ? (int) ($info['user_count'] ?? 0) : 0;
        }

        return $out;
    }

    /**
     * @return list<string> member user ids that are UUIDs (empty when the call failed; that is counted, never trusted)
     */
    private function members(Pusher $pusher, string $channel, ReconcileStats $stats): array
    {
        try {
            $result = $pusher->get('/channels/'.$channel.'/users', [], true);
        } catch (ApiErrorException $e) {
            // 404 for a channel Reverb just listed: this node does not hold it (scaling mode). A gap, not success.
            $e->getCode() === 404 ? $stats->gaps++ : $stats->skipped++;
            Log::warning('realtime.reconcile.members_failed', ['channel_hash' => hash('sha256', $channel), 'status' => $e->getCode()]);

            return [];
        } catch (Throwable $e) {
            $stats->skipped++;
            Log::warning('realtime.reconcile.members_failed', ['channel_hash' => hash('sha256', $channel), 'exception' => $e::class]);

            return [];
        }

        $ids = [];
        foreach (is_array($result) && is_array($result['users'] ?? null) ? $result['users'] : [] as $member) {
            if (! is_array($member) || ! isset($member['id']) || ! is_scalar($member['id'])) {
                continue;
            }
            $id = (string) $member['id'];
            if (! Str::isUuid($id)) {
                // Not an id we ever issue: an anomaly, never passed on (hash only in the log).
                $stats->anomalies++;
                Log::warning('realtime.reconcile.non_uuid_member', ['member_hash' => hash('sha256', $id)]);

                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function anomaly(ChannelRef $ref, int $userCount, ReconcileStats $stats): void
    {
        $stats->anomalies++;
        Log::error('realtime.reconcile.user_channel_anomaly', ['user_id' => $ref->id, 'user_count' => $userCount]);

        try {
            if (Cache::add('realtime-reconcile:anomaly:'.hash('sha256', $ref->id), 1, 900)) {
                $this->events->record(SecurityEventType::RealtimeChannelAnomaly, null, ['channel_kind' => $ref->kind->value, 'user_count' => $userCount]);
            }
        } catch (Throwable) {
            // The log line above is the fallback.
        }
    }

    /**
     * @param  array<string, list<ChannelRef>>  $pairs
     * @return bool true when the users or credentials could not be looked up (fail open)
     */
    private function evaluate(array $pairs, ReconcileStats $stats): bool
    {
        if ($pairs === []) {
            return false;
        }
        $ids = array_map('strval', array_keys($pairs));
        $stats->users = count($ids);

        try {
            /** @var Collection<string, User> $users */
            $users = collect();
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach (User::whereIn('id', $chunk)->get() as $found) {
                    $users->put($found->id, $found);
                }
            }
            $live = array_flip($this->liveSessions->liveUserIds($ids));
        } catch (Throwable $e) {
            // Cannot tell who exists: fail open for this run rather than close sockets on a database hiccup.
            $stats->skipped += count($ids);
            Log::warning('realtime.reconcile.lookup_failed', ['exception' => $e::class]);

            return true;
        }

        $cap = max(1, (int) config('realtime.reconcile.max_terminations_per_run'));
        foreach ($pairs as $userId => $channels) {
            if ($stats->terminated >= $cap) {
                $stats->partial = true; // the rest are still connected, so the next run finds them again
                break;
            }
            $userId = (string) $userId;
            $user = $users->get($userId);
            $reason = null;

            if (! $user instanceof User) {
                $reason = 'reconcile_no_account';
            } elseif (! isset($live[$userId])) {
                $reason = 'reconcile_no_credential';
            } else {
                foreach ($channels as $channel) {
                    try {
                        if (! $this->authorizer->allows($user, $channel)) {
                            $reason = 'reconcile_'.$channel->kind->value;
                            break;
                        }
                    } catch (Throwable $e) {
                        $stats->skipped++;
                        Log::warning('realtime.reconcile.authorize_failed', ['user_id' => $userId, 'exception' => $e::class]);
                    }
                }
            }

            if ($reason !== null) {
                $this->terminate($userId, $user instanceof User, $reason, $stats);
            }
        }

        return false;
    }

    private function terminate(string $userId, bool $knownUser, string $reason, ReconcileStats $stats): void
    {
        // Once per user per run (the caller groups by user) and not again within 30 s. A cache fault must not stop the close.
        try {
            if (! Cache::add('realtime-reconcile:terminated:'.hash('sha256', $userId), 1, 30)) {
                return;
            }
        } catch (Throwable) {
            // proceed
        }

        if (! $this->sessions->terminateNow($userId, $reason)) {
            return; // recorded by terminateNow; not counted as terminated, retried next run
        }
        $stats->terminated++;
        Log::warning('realtime.reconcile.terminated', ['user_id' => $userId, 'reason' => $reason]);

        try {
            // A catch means a revocation trigger was missed (or a credential expired): one Warning event per user per hour.
            if (Cache::add('realtime-reconcile:revoked:'.hash('sha256', $userId), 1, 3600)) {
                $this->events->record(SecurityEventType::RealtimeReconcileRevoked, $knownUser ? $userId : null, ['reason' => $reason]);
            }
        } catch (Throwable) {
            // The log line above is the fallback; recording must never stop the sweep.
        }
    }

    /** End of a run: the heartbeats and the consecutive-degraded counter. */
    private function finish(ReconcileStats $stats, bool $lookupFailed): void
    {
        try {
            Cache::put(self::LAST_RUN_KEY, time(), 86400);
            $clean = ! $stats->failed && ! $lookupFailed && $stats->skipped === 0 && $stats->gaps === 0;
            if ($clean) {
                Cache::put(self::HEARTBEAT_KEY, time(), 86400); // `last_ok`: everything was looked at and answered
            }

            if ($stats->failed) {
                return; // listFailed() already counted it
            }
            // Degraded: the lookup failed, pairs were skipped, or coverage gaps exceed the threshold. A time-budget or
            // termination-cap cut (partial) is not degraded, and a few gaps are normal churn (a channel closing between
            // the list and the members call).
            $gapLimit = max((int) config('realtime.reconcile.gap_min_count'), (int) ceil((float) config('realtime.reconcile.gap_ratio') * $stats->channels));
            if (! $lookupFailed && $stats->skipped === 0 && $stats->gaps < $gapLimit) {
                Cache::forget(self::DEGRADED_KEY);

                return;
            }
            Cache::add(self::DEGRADED_KEY, 0, 3600);
            $degraded = (int) Cache::increment(self::DEGRADED_KEY);
            if ($degraded >= (int) config('realtime.reconcile.failure_threshold')) {
                $this->alert($degraded, 'degraded');
            }
        } catch (Throwable $e) {
            Log::warning('realtime.reconcile.heartbeat_failed', ['exception' => $e::class]);
        }
    }

    private function listFailed(Throwable $e): void
    {
        try {
            Cache::add(self::FAILURES_KEY, 0, 3600);
            $failures = (int) Cache::increment(self::FAILURES_KEY);
        } catch (Throwable) {
            $failures = 1;
        }
        Log::warning('realtime.reconcile.api_failed', ['consecutive_failures' => $failures, 'exception' => $e::class, 'status' => $e instanceof ApiErrorException ? $e->getCode() : null]);

        if ($failures >= (int) config('realtime.reconcile.failure_threshold')) {
            $this->alert($failures, 'api_unavailable');
        }
    }

    /** At most once per 15 minutes: a Sentry report first (a database outage must not hide it), then the High event. */
    private function alert(int $consecutive, string $why): void
    {
        Log::error('realtime.reconcile.unavailable', ['consecutive_runs' => $consecutive, 'why' => $why]);

        try {
            if (Cache::has('realtime-reconcile:failed-alert')) {
                return;
            }
        } catch (Throwable) {
            // proceed: better a repeated alert than none
        }

        try {
            report(new RealtimeReconcileFailedException);
        } catch (Throwable) {
            // Log::error above is the fallback.
        }

        try {
            $this->events->record(SecurityEventType::RealtimeReconcileFailed, null, ['consecutive_runs' => $consecutive, 'why' => $why]);
            Cache::put('realtime-reconcile:failed-alert', 1, 900); // set only after the event is stored, so a failed write is retried next run
        } catch (Throwable) {
            // retried next run
        }
    }

    private function elapsedMs(int $startedNs): int
    {
        return intdiv(hrtime(true) - $startedNs, 1_000_000);
    }
}
