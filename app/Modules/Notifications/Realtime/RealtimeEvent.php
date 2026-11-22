<?php

namespace App\Modules\Notifications\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use LogicException;

/**
 * Base for every broadcast event (phase 5 plan Q12, CLAUDE.md §19).
 *
 * - Queued on the `notifications` queue, never in the request.
 * - Dispatched only after the surrounding database transaction commits, so a client never
 *   hears about state that was rolled back.
 * - A hint, not a record: PostgreSQL is the truth and clients refetch after reconnecting.
 * - Fail closed on content. A subclass lists the payload keys it may send in ALLOWED_KEYS
 *   (empty by default: nothing). Values are ids, counts, queue numbers, statuses and
 *   timestamps: scalars or null. A key that looks like personal or clinical data (name, note,
 *   diagnosis, prescription, membership, token, phone, email, address) is refused even if
 *   listed. Channels must be private. A violation throws before anything is published.
 */
abstract class RealtimeEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** The payload keys this event may send. Override in the subclass; the default sends nothing. */
    public const ALLOWED_KEYS = [];

    /** Keys that must never travel, whatever a subclass lists. */
    private const FORBIDDEN_KEY_PATTERN = '/name|note|diagnos|prescri|membership|token|phone|email|address/i';

    /** Retries and timeouts for the queued publish (read by BroadcastEvent). */
    public int $tries = 3;

    public int $timeout = 10;

    /** @var list<int> */
    public array $backoff = [2, 10];

    /**
     * The private channels this event goes to (from Channels).
     *
     * @return list<Channel>
     */
    abstract protected function channels(): array;

    /**
     * The payload: only keys in ALLOWED_KEYS, scalar or null values (checked at publish time).
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /** @return list<Channel> */
    final public function broadcastOn(): array
    {
        $channels = $this->channels();
        foreach ($channels as $channel) {
            if (! $channel instanceof PrivateChannel) {
                throw new LogicException(static::class.' must broadcast on private channels only.');
            }
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    final public function broadcastWith(): array
    {
        $payload = $this->payload();

        foreach ($payload as $key => $value) {
            if (preg_match(self::FORBIDDEN_KEY_PATTERN, (string) $key) === 1) {
                throw new LogicException(static::class." payload key [{$key}] looks like personal or clinical data.");
            }
            if (! in_array($key, static::ALLOWED_KEYS, true)) {
                throw new LogicException(static::class." payload key [{$key}] is not in ALLOWED_KEYS.");
            }
            if ($value !== null && ! is_scalar($value)) {
                throw new LogicException(static::class." payload key [{$key}] must be a scalar or null.");
            }
        }

        return $payload;
    }

    public function broadcastQueue(): string
    {
        return 'notifications';
    }

    /** The short event name clients listen for, e.g. "WalkInQueueUpdated". */
    public function broadcastAs(): string
    {
        return class_basename($this);
    }
}
