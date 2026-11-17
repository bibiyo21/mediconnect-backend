<?php

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * LOCAL ONLY. Shows SMS messages in Mailpit instead of sending them, so codes
 * are visible to developers without ever being written to a log.
 * Refuses to run in any environment other than local or testing.
 */
final class MailPreviewSmsGateway implements SmsGateway
{
    public function send(string $e164, string $text): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The mail-preview SMS driver is only allowed locally.');
        }

        Mail::raw($text, fn ($message) => $message
            ->to('sms-preview@mediconnect.localhost')
            ->subject('SMS to '.PhoneNumber::parse($e164)->masked()));
    }
}
