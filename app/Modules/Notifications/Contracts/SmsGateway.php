<?php

namespace App\Modules\Notifications\Contracts;

/**
 * SMS provider abstraction (§18.2, provider still to be chosen: D-07).
 */
interface SmsGateway
{
    public function send(string $e164, string $text): void;
}
