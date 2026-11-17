<?php

namespace App\Shared\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes secrets and sensitive personal data from log records (§29, §32.6):
 * passwords, tokens, OTP codes, membership and licence numbers, clinical text.
 *
 * Keys are matched case-insensitively at any depth of context and extra.
 * Messages are scrubbed for bearer tokens and "key=value" pairs of the same
 * keys. This is a safety net: code must still avoid logging such data.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const REDACTED = '[REDACTED]';

    /**
     * Normalized key fragments. A key is redacted when, lowercased with
     * '-' and ' ' turned into '_', it equals or ends with one of these.
     */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'remember_token', 'api_key', 'secret',
        'client_secret', 'authorization', 'cookie', 'set_cookie', 'x_xsrf_token', 'xsrf_token',
        'otp', 'otp_code', 'code', 'recovery_code', 'recovery_codes', 'totp',
        'membership_number', 'prc_license_number', 'license_number', 'account_number',
        'card_number', 'cvv', 'cvc', 'tin',
        'chief_complaint', 'diagnosis', 'assessment', 'treatment_plan', 'medical_notes', 'notes',
        'body', 'message_body',
    ];

    /**
     * Generic words that are sensitive only as the whole key: "code" (OTP) but
     * not "error_code" or "status_code". Not applied to free-text messages.
     */
    private const EXACT_ONLY_KEYS = ['code', 'body', 'notes', 'tin'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrubMessage($record->message),
            context: self::scrubArray($record->context),
            extra: self::scrubArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = self::scrubArray($value);
            } elseif (is_string($value)) {
                $data[$key] = self::scrubMessage($value);
            }
        }

        return $data;
    }

    public static function scrubMessage(string $message): string
    {
        $message = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-\._~\+\/=|]+/i', '$1 '.self::REDACTED, $message) ?? $message;

        $messageKeys = array_diff(self::SENSITIVE_KEYS, self::EXACT_ONLY_KEYS);
        $keys = implode('|', array_map(fn (string $k) => preg_quote($k, '/'), $messageKeys));

        // The lookahead leaves "Authorization: Bearer [REDACTED]" to the rule above.
        return preg_replace('/(["\']?\b(?:'.$keys.')\b["\']?\s*[:=]\s*)(?!(?:Bearer|Basic)\s)("[^"]*"|\'[^\']*\'|[^\s,&;}]+)/i', '$1'.self::REDACTED, $message) ?? $message;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive) {
                return true;
            }

            if (! in_array($sensitive, self::EXACT_ONLY_KEYS, true) && str_ends_with($normalized, '_'.$sensitive)) {
                return true;
            }
        }

        return false;
    }
}
