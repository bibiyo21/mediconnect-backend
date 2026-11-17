<?php

namespace App\Shared\Services;

use App\Shared\Events\DomainEvent;
use App\Shared\Jobs\DispatchOutbox;
use App\Shared\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Transactional outbox (architecture.md §15.3).
 *
 * record() inserts the event in the caller's transaction, so it exists if and
 * only if the state change committed. A relay then hands events to Laravel's
 * event dispatcher: right after commit (queued job) and every minute from the
 * scheduler as a safety net. A failing event is retried with backoff and does
 * not hold up the others.
 */
final class Outbox
{
    private const BATCH = 100;

    private const MAX_BACKOFF_MINUTES = 60;

    public function record(DomainEvent $event): void
    {
        $type = $event::eventType();

        if ((config('outbox.events')[$type] ?? null) !== $event::class) {
            throw new LogicException("Domain event [{$type}] is not registered in config/outbox.php.");
        }

        DB::table('outbox_events')->insert([
            'event_id' => (string) Str::uuid7(),
            'event_type' => $type,
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'payload' => json_encode($event->payload()),
            'request_id' => Context::get('request_id'),
            'occurred_at' => now(),
            'available_at' => now(),
        ]);

        DB::afterCommit(fn () => DispatchOutbox::dispatch());
    }

    /** Relays due events; returns how many were dispatched. */
    public function relay(): int
    {
        $dispatched = 0;

        DB::transaction(function () use (&$dispatched) {
            // SKIP LOCKED: several relays (job + scheduler) never pick the same row.
            $rows = DB::table('outbox_events')
                ->whereNull('dispatched_at')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit(self::BATCH)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($rows as $row) {
                try {
                    DB::transaction(fn () => $this->dispatchEvent((string) $row->event_type, (string) $row->aggregate_id, (string) $row->payload));
                    DB::table('outbox_events')->where('id', $row->id)->update(['dispatched_at' => now(), 'attempts' => $row->attempts + 1]);
                    $dispatched++;
                } catch (Throwable $e) {
                    $attempts = $row->attempts + 1;
                    DB::table('outbox_events')->where('id', $row->id)->update([
                        'attempts' => $attempts,
                        'available_at' => now()->addMinutes(min(2 ** $attempts, self::MAX_BACKOFF_MINUTES)),
                        'last_error' => mb_substr(RedactSensitiveData::scrubMessage($e::class.': '.$e->getMessage()), 0, 1000),
                    ]);
                    Log::warning('Outbox event failed to dispatch', ['event_id' => $row->event_id, 'event_type' => $row->event_type, 'attempts' => $attempts]);
                }
            }
        });

        return $dispatched;
    }

    private function dispatchEvent(string $type, string $aggregateId, string $payloadJson): void
    {
        /** @var class-string<DomainEvent>|null $class */
        $class = config('outbox.events')[$type] ?? null;

        if ($class === null) {
            throw new LogicException("No class registered for outbox event [{$type}].");
        }

        /** @var array<string, scalar|null> $payload */
        $payload = json_decode($payloadJson, true) ?? [];

        Event::dispatch($class::fromOutbox($aggregateId, $payload));
    }
}
