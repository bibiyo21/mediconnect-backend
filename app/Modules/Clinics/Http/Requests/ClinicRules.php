<?php

namespace App\Modules\Clinics\Http\Requests;

/** Shared validation pieces for clinic data. */
final class ClinicRules
{
    /** R-16: phone numbers belong in the phone field, never in names or notes. */
    public const NO_PHONE_IN_TEXT = 'not_regex:/(\+?\d[\d\s().-]{6,}\d)/';

    /** Mobile or landline: digits with an optional leading +, spaces, dashes or brackets. */
    public const PHONE = 'regex:/^\+?[0-9][0-9\s().-]{5,18}[0-9]$/';

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'name.not_regex' => 'Keep phone numbers out of the name; use the phone field.',
            'location.name.not_regex' => 'Keep phone numbers out of the name; use the phone field.',
            'phone.regex' => 'Enter a phone number with digits only, for example +63 2 8123 4567 or 0917 123 4567.',
            'location.phone.regex' => 'Enter a phone number with digits only, for example +63 2 8123 4567 or 0917 123 4567.',
        ];
    }

    public static function normalisePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        return mb_substr($digits, 0, 16);
    }

    /** @return array<string, mixed> location fields; $prefix e.g. 'location.' */
    public static function location(string $prefix = '', bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            "{$prefix}name" => [$req, 'string', 'min:2', 'max:160', self::NO_PHONE_IN_TEXT],
            "{$prefix}address_line1" => [$req, 'string', 'max:255'],
            "{$prefix}address_line2" => ['sometimes', 'nullable', 'string', 'max:255'],
            "{$prefix}barangay" => ['sometimes', 'nullable', 'string', 'max:100'],
            "{$prefix}city" => [$req, 'string', 'max:100'],
            "{$prefix}province" => ['sometimes', 'nullable', 'string', 'max:100'],
            "{$prefix}postal_code" => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}$/'],
            // No `sometimes`: sending one coordinate without the other must fail validation.
            "{$prefix}latitude" => ['nullable', 'numeric', 'between:-90,90', "required_with:{$prefix}longitude"],
            "{$prefix}longitude" => ['nullable', 'numeric', 'between:-180,180', "required_with:{$prefix}latitude"],
            "{$prefix}timezone" => ['sometimes', 'string', 'timezone:all'],
            "{$prefix}phone" => ['sometimes', 'nullable', 'string', self::PHONE],
            "{$prefix}hospital_name" => ['sometimes', 'nullable', 'string', 'max:160', self::NO_PHONE_IN_TEXT],
            "{$prefix}status" => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ];
    }
}
