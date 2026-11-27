<?php

namespace App\Modules\Consultations\Services;

use InvalidArgumentException;

/** config('consultations') as a checked value, read at boot by the provider so an env typo stops the application. */
final class ConsultationsConfig
{
    public function __construct(public readonly int $careAccessDays)
    {
        if ($careAccessDays < 1 || $careAccessDays > 365) {
            throw new InvalidArgumentException('Invalid consultations configuration: care_access_days must be 1 to 365.');
        }
    }

    public static function fromConfig(): self
    {
        $days = config('consultations.care_access_days');
        if (! is_int($days) && ! (is_string($days) && ctype_digit($days))) {
            throw new InvalidArgumentException('Invalid consultations configuration: care_access_days must be an integer.');
        }

        return new self((int) $days);
    }
}
