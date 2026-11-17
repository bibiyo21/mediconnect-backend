<?php

namespace Tests\Support;

use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Shared\Enums\DeliveryChannel;

/**
 * Captures codes and notices instead of sending them.
 */
final class FakeOneTimeMessages implements SendsOneTimeMessages
{
    /** @var list<array{channel: DeliveryChannel, destination: string, code: string}> */
    public array $codes = [];

    /** @var list<array{channel: DeliveryChannel, destination: string, notice: string}> */
    public array $notices = [];

    public function sendCode(DeliveryChannel $channel, string $destination, string $code, int $validMinutes): void
    {
        $this->codes[] = compact('channel', 'destination', 'code');
    }

    public function sendNotice(DeliveryChannel $channel, string $destination, string $notice): void
    {
        $this->notices[] = compact('channel', 'destination', 'notice');
    }

    public function lastCodeFor(string $destination): ?string
    {
        foreach (array_reverse($this->codes) as $sent) {
            if ($sent['destination'] === $destination) {
                return $sent['code'];
            }
        }

        return null;
    }
}
