<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsGateway;
use RuntimeException;

/**
 * Default until a real SMS provider is chosen (D-07): fails loudly instead of
 * pretending a code was delivered.
 */
final class DisabledSmsGateway implements SmsGateway
{
    public function send(string $e164, string $text): void
    {
        throw new RuntimeException('No SMS provider is configured (SMS_DRIVER). See decision D-07.');
    }
}
