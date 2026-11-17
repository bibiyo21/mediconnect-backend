<?php

namespace App\Modules\Notifications\Contracts;

use App\Shared\Enums\DeliveryChannel;

/**
 * Delivery of one-time codes and account notices by email or SMS.
 * Delivery is queued (never synchronous in the request, §32.5) and the job
 * payload is encrypted, because it carries the code.
 */
interface SendsOneTimeMessages
{
    public function sendCode(DeliveryChannel $channel, string $destination, string $code, int $validMinutes): void;

    /** A notice without a code, e.g. "someone tried to register with this number". */
    public function sendNotice(DeliveryChannel $channel, string $destination, string $notice): void;
}
