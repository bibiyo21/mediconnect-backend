<?php

namespace App\Shared\ValueObjects;

use App\Shared\Exceptions\InvalidValue;
use JsonSerializable;

/**
 * An amount of money in integer minor units (D-18). PHP centavos by default.
 *
 * All arithmetic stays in integers; floats are never accepted.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public static function of(int $minorUnits, string $currency = 'PHP'): self
    {
        $currency = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidValue("Invalid ISO 4217 currency code: {$currency}");
        }

        return new self($minorUnits, $currency);
    }

    public static function zero(string $currency = 'PHP'): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Share of this amount in basis points (10000 = 100%), rounded half up
     * (half away from zero for negative amounts). Used for commissions.
     */
    public function percentage(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidValue('Basis points cannot be negative.');
        }

        $product = $this->amount * $basisPoints;
        $quotient = intdiv($product, 10000);
        $remainder = abs($product % 10000);

        if ($remainder * 2 >= 10000) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return new self($quotient, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    /**
     * Display form, e.g. "₱1,500.00". Currencies with a symbol other than PHP
     * fall back to the ISO code ("USD 12.50"); precision is 2 decimals (§1.2).
     */
    public function format(): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $absolute = abs($this->amount);
        $number = number_format(intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $symbol = $this->currency === 'PHP' ? '₱' : $this->currency.' ';

        return $sign.$symbol.$number;
    }

    /**
     * API shape from api-specification.md §1.6.
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidValue("Currency mismatch: {$this->currency} and {$other->currency}");
        }
    }
}
