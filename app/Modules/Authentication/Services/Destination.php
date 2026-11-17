<?php

namespace App\Modules\Authentication\Services;

use App\Shared\Enums\DeliveryChannel;
use App\Shared\Services\Fingerprint;
use App\Shared\ValueObjects\PhoneNumber;

/**
 * A normalized email address or mobile number that a code is sent to.
 */
final readonly class Destination
{
    private function __construct(
        public DeliveryChannel $channel,
        public string $value,
    ) {}

    public static function email(string $email): self
    {
        return new self(DeliveryChannel::Email, mb_strtolower(trim($email)));
    }

    public static function mobile(string $mobile): self
    {
        return new self(DeliveryChannel::Sms, PhoneNumber::parse($mobile)->e164);
    }

    public function hash(): string
    {
        return Fingerprint::of('destination:'.$this->value);
    }

    public function masked(): string
    {
        if ($this->channel === DeliveryChannel::Sms) {
            return PhoneNumber::parse($this->value)->masked();
        }

        [$local, $domain] = explode('@', $this->value, 2) + [1 => ''];

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    public function phoneNumber(): ?PhoneNumber
    {
        return $this->channel === DeliveryChannel::Sms ? PhoneNumber::parse($this->value) : null;
    }
}
