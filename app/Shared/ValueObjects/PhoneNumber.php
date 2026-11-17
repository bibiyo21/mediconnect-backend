<?php

namespace App\Shared\ValueObjects;

use App\Shared\Exceptions\InvalidValue;
use JsonSerializable;
use Stringable;

/**
 * A mobile number stored in E.164 form.
 *
 * Only Philippine mobile numbers (+63 9XX XXX XXXX) are accepted for now
 * (initial market, §1.2). Other countries are added when the platform expands.
 */
final readonly class PhoneNumber implements JsonSerializable, Stringable
{
    private function __construct(public string $e164) {}

    /**
     * Accepts the common local spellings: 09171234567, 9171234567,
     * 639171234567, +639171234567, with spaces, dashes, dots or brackets.
     */
    public static function parse(string $input): self
    {
        $digits = preg_replace('/[\s\-\.\(\)]/', '', trim($input)) ?? '';

        $national = match (true) {
            preg_match('/^\+639\d{9}$/', $digits) === 1 => substr($digits, 3),
            preg_match('/^639\d{9}$/', $digits) === 1 => substr($digits, 2),
            preg_match('/^09\d{9}$/', $digits) === 1 => substr($digits, 1),
            preg_match('/^9\d{9}$/', $digits) === 1 => $digits,
            default => throw new InvalidValue('Enter a valid Philippine mobile number.'),
        };

        return new self('+63'.$national);
    }

    /**
     * Masked display, e.g. "+63 9•• ••• 4567", for UIs, notices and logs.
     */
    public function masked(): string
    {
        return '+63 '.substr($this->e164, 3, 1).'•• ••• '.substr($this->e164, -4);
    }

    public function equals(self $other): bool
    {
        return $this->e164 === $other->e164;
    }

    public function __toString(): string
    {
        return $this->e164;
    }

    public function jsonSerialize(): string
    {
        return $this->e164;
    }
}
