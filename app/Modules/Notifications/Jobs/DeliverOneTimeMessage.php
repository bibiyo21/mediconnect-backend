<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Shared\Enums\DeliveryChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Encrypted on the queue (it carries a code or a contact address). Short
 * retry window: a code is useless after it expires.
 */
final class DeliverOneTimeMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 20, 60];

    public function __construct(
        public readonly DeliveryChannel $channel,
        public readonly string $destination,
        public readonly string $subject,
        public readonly string $text,
    ) {
        $this->onQueue($channel === DeliveryChannel::Sms ? 'sms' : 'email');
    }

    public function handle(SmsGateway $sms): void
    {
        if ($this->channel === DeliveryChannel::Sms) {
            $sms->send($this->destination, $this->text);

            return;
        }

        Mail::raw($this->text, fn ($message) => $message->to($this->destination)->subject($this->subject));
    }
}
