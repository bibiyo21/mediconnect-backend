<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Notifications\Jobs\DeliverOneTimeMessage;
use App\Shared\Enums\DeliveryChannel;

final class OneTimeMessageSender implements SendsOneTimeMessages
{
    public function sendCode(DeliveryChannel $channel, string $destination, string $code, int $validMinutes): void
    {
        $text = "Your MediConnect code is {$code}. It expires in {$validMinutes} minutes. Never share this code with anyone, including MediConnect staff.";

        DeliverOneTimeMessage::dispatch($channel, $destination, 'Your MediConnect code', $text);
    }

    public function sendNotice(DeliveryChannel $channel, string $destination, string $notice): void
    {
        DeliverOneTimeMessage::dispatch($channel, $destination, 'MediConnect account notice', $notice);
    }
}
