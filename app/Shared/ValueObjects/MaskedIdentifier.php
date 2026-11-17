<?php

namespace App\Shared\ValueObjects;

use JsonSerializable;
use Stringable;

/**
 * Display-safe form of a sensitive identifier (membership number, licence
 * number, account number). Only the last few characters are kept; the full
 * value is never held, so this object is always safe to log or return.
 */
final readonly class MaskedIdentifier implements JsonSerializable, Stringable
{
    private function __construct(public string $visibleSuffix) {}

    public static function from(string $value, int $visibleCharacters = 4): self
    {
        $value = preg_replace('/\s+/', '', $value) ?? '';
        $visibleCharacters = max(0, $visibleCharacters);

        // Short values reveal nothing: showing 4 of 5 characters is not masking.
        if (mb_strlen($value) <= $visibleCharacters * 2) {
            return new self('');
        }

        return new self(mb_substr($value, -$visibleCharacters));
    }

    public function __toString(): string
    {
        return $this->visibleSuffix === '' ? '••••' : '•••• '.$this->visibleSuffix;
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
