<?php

namespace App\Shared\Validation;

use Carbon\CarbonImmutable;

/**
 * Birth date rules. Dates are local calendar dates in the market timezone
 * (config/mediconnect.php), not UTC, so "today" matches the user's calendar.
 */
final class BirthDate
{
    /**
     * An account holder's birth date: required, real, and at least the minimum
     * account age (D-37).
     *
     * @return list<string>
     */
    public static function accountHolder(): array
    {
        return ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.self::latestForAdult()];
    }

    /**
     * Anyone's birth date (for example a dependent): required, real, not in the future.
     *
     * @return list<string>
     */
    public static function anyAge(): array
    {
        return ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.self::today()->toDateString()];
    }

    /**
     * Plain-language messages for accountHolder().
     *
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        $age = (int) config('mediconnect.minimum_account_age');

        return [
            "{$field}.required" => 'Enter your date of birth.',
            "{$field}.date_format" => 'Enter a real date, for example 1990-05-17.',
            "{$field}.after" => 'Enter a real date, for example 1990-05-17.',
            "{$field}.before_or_equal" => "You must be {$age} or older to have an account. A parent or guardian can add a child as a dependent.",
        ];
    }

    /**
     * Plain-language messages for anyAge().
     *
     * @return array<string, string>
     */
    public static function anyAgeMessages(string $field): array
    {
        return [
            "{$field}.required" => 'Enter the date of birth.',
            "{$field}.date_format" => 'Enter a real date, for example 2018-05-17.',
            "{$field}.after" => 'Enter a real date, for example 2018-05-17.',
            "{$field}.before_or_equal" => 'The date of birth cannot be in the future.',
        ];
    }

    /** The latest birth date that is old enough to hold an account today. */
    public static function latestForAdult(): string
    {
        return self::today()->subYears((int) config('mediconnect.minimum_account_age'))->toDateString();
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('mediconnect.timezone'))->startOfDay();
    }
}
