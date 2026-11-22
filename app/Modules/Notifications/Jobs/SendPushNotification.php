<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Contracts\PushGateway;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes one stored notification (§24 SendPushNotification). Idempotent and safe with several workers:
 *
 * - it claims the delivery row with one UPDATE (QUEUED to SENDING, stamping claimed_at); a repeat run finds it
 *   finished and ends. A claim younger than `services.push.reclaim_after_minutes` belongs to a live worker: this run
 *   is RELEASED back to the queue until that claim would expire (so a worker that died is taken over, a live one is
 *   not duplicated), and releases do not use up attempts (retryUntil, not tries);
 * - the job timeout is far below the reclaim window, so a worker still sending has been killed long before another
 *   may take over its claim;
 * - an error before the provider accepted the push puts the row back to QUEUED for the retry;
 * - provider_called_at is stamped just before the provider is called. From then on the push may be out: the row is
 *   never reset to QUEUED and failed() never marks it FAILED; the claim simply expires and a later run re-sends with
 *   the notification id as the provider's collapse key, which makes the rare duplicate harmless;
 * - after the final failure failed() marks a row whose provider was never called FAILED.
 * No device token, or push switched off in the user's preferences, marks it SKIPPED.
 */
final class SendPushNotification implements ShouldQueue
{
    use Queueable;

    /** Real failures allowed (releases and reclaims do not count). */
    public int $maxExceptions = 3;

    /** Seconds; far below the reclaim window (at least one minute), so an overdue worker is killed before it can be reclaimed. */
    public int $timeout = 25;

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue('notifications');
    }

    /** Releases for a live claim must not exhaust attempts: the job lives until a deadline instead. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(max(1, (int) config('services.push.reclaim_after_minutes')) * 2 + 5);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(PushGateway $push): void
    {
        $notification = DB::table('notifications')->where('id', $this->notificationId)->first();
        if ($notification === null) {
            return;
        }
        if (! $this->claim()) {
            $this->waitForLiveClaim();

            return;
        }
        $deliveryId = DB::table('notification_deliveries')->where('notification_id', $this->notificationId)->where('channel', 'PUSH')->value('id');

        try {
            $userId = (string) $notification->notifiable_id;
            $off = DB::table('notification_preferences')->where('user_id', $userId)->where('event_type', $notification->type)->where('channel', 'PUSH')->where('enabled', false)->exists();
            $tokens = DB::table('device_tokens as t')->join('user_devices as d', 'd.id', '=', 't.user_device_id')
                ->where('d.user_id', $userId)->whereNull('d.revoked_at')->whereNull('t.invalidated_at')->pluck('t.token')->map(fn ($t) => (string) $t)->all();

            if ($off || $tokens === []) {
                $this->finish($deliveryId, 'SKIPPED', null);

                return;
            }

            /** @var array{title?: string, body?: string} $data */
            $data = json_decode((string) $notification->data, true) ?? [];
            DB::table('notification_deliveries')->where('id', $deliveryId)->update(['provider_called_at' => now()]);
            $sent = $push->send($this->notificationId, (string) $notification->type, (string) ($data['title'] ?? ''), (string) ($data['body'] ?? ''), array_values($tokens), $this->notificationId);
        } catch (Throwable $e) {
            // The provider did not accept it (or was never called): free the claim so the retry can try again.
            DB::table('notification_deliveries')->where('id', $deliveryId)->where('status', 'SENDING')->update(['status' => 'QUEUED', 'claimed_at' => null, 'provider_called_at' => null]);

            throw $e;
        }

        // The provider has answered. From here the row is never reset to QUEUED (no duplicate push). The log and
        // disabled drivers deliver nothing: SKIPPED, never SENT.
        $this->finish($deliveryId, $sent ? 'SENT' : 'SKIPPED', (string) config('services.push.driver'), $sent ? null : 'No push provider configured (log or disabled driver).');
    }

    /** After the final failure: FAILED, with one more attempt counted, unless the provider may already have the push. */
    public function failed(Throwable $e): void
    {
        DB::table('notification_deliveries')->where('notification_id', $this->notificationId)->where('channel', 'PUSH')->whereIn('status', ['QUEUED', 'SENDING'])->whereNull('provider_called_at')
            ->update(['status' => 'FAILED', 'attempts' => DB::raw('attempts + 1'), 'error' => mb_substr($e::class, 0, 200)]);
    }

    /** One UPDATE decides which worker sends: QUEUED, or SENDING whose claim has expired. */
    private function claim(): bool
    {
        $expired = now()->subMinutes(max(1, (int) config('services.push.reclaim_after_minutes')));

        return DB::table('notification_deliveries')->where('notification_id', $this->notificationId)->where('channel', 'PUSH')
            ->where(fn ($q) => $q->where('status', 'QUEUED')->orWhere(fn ($w) => $w->where('status', 'SENDING')->where('claimed_at', '<', $expired)))
            ->update(['status' => 'SENDING', 'claimed_at' => now(), 'provider_called_at' => null]) > 0;
    }

    /** Lost the claim: a finished row ends this run; a fresh claim of another worker releases it until that claim expires. */
    private function waitForLiveClaim(): void
    {
        $row = DB::table('notification_deliveries')->where('notification_id', $this->notificationId)->where('channel', 'PUSH')->first(['status', 'claimed_at']);
        if ($row === null || ! in_array($row->status, ['QUEUED', 'SENDING'], true)) {
            return;
        }

        $window = max(1, (int) config('services.push.reclaim_after_minutes')) * 60;
        $age = $row->claimed_at === null ? 0 : CarbonImmutable::parse((string) $row->claimed_at)->diffInSeconds(now(), false);
        $this->release(max(1, (int) ceil($window - $age) + 1));
    }

    private function finish(mixed $deliveryId, string $status, ?string $provider, ?string $note = null): void
    {
        try {
            DB::table('notification_deliveries')->where('id', $deliveryId)->update([
                'status' => $status, 'provider' => $provider, 'error' => $note, 'attempts' => DB::raw('attempts + 1'), 'sent_at' => $status === 'SENT' ? now() : null,
            ]);
        } catch (Throwable $e) {
            // The push may already be out: leave the row SENDING (its claim expires) and never requeue it.
            Log::warning('Push delivery row could not be finished', ['notification_id' => $this->notificationId, 'exception' => $e::class]);

            throw $e;
        }
    }
}
