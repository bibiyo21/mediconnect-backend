<?php

namespace App\Shared\Logging;

use Illuminate\Database\QueryException;
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
            context: self::scrubArray(self::flattenThrowables($record->context)),
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

    /**
     * A QueryException message carries the SQL with its bindings inlined ("... SQL: insert ... values
     * (jane@example.com, ...)") and PostgreSQL's DETAIL line (the offending values). Neither may reach a
     * log or Sentry: keep the SQLSTATE and the error text, drop the connection, DETAIL and bindings.
     * With $sql (the statement with ? placeholders, from QueryException::getSql()) the shape is
     * "SQLSTATE[..]: ... (SQL: select ... where id = ?)".
     */
    public static function scrubQueryMessage(string $message, ?string $sql = null): string
    {
        if (! str_contains($message, 'SQLSTATE[')) {
            return $message;
        }

        $cut = strpos($message, '(Connection:');
        if ($cut !== false) {
            $message = rtrim(substr($message, 0, $cut));
        }
        $message = preg_replace('/\R\s*(?:DETAIL|CONTEXT|HINT|LINE \d+):.*$/s', '', $message) ?? $message;
        // "invalid input syntax for type uuid: "<user input>"" repeats the input.
        $message = preg_replace('/(invalid input syntax for type [a-z0-9 ]+):.*$/si', '$1', $message) ?? $message;
        // Any quoted value after a colon in the error text ("...for enum x: "value"") is user data.
        $at = strpos($message, 'ERROR:');
        if ($at !== false) {
            $message = substr($message, 0, $at + 6).(preg_replace('/:\s*"[^"]*"/', ': "'.self::REDACTED.'"', substr($message, $at + 6)) ?? substr($message, $at + 6));
        }
        $message = rtrim($message);

        return $sql === null ? $message : $message.' (SQL: '.$sql.')';
    }

    /**
     * Replaces every Throwable in a log context (at any depth) by a scrubbed, argument-free description, because
     * the log formatter would otherwise serialize the raw message (a QueryException has its bindings inlined) and
     * the trace with its call arguments (phone numbers, tokens, clinical text passed to the failing function).
     * The causes (getPrevious) are walked too, so a RuntimeException wrapping a QueryException is just as safe.
     *
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    private static function flattenThrowables(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = self::describeThrowable($value);
            } elseif (is_array($value)) {
                $context[$key] = self::flattenThrowables($value);
            }
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private static function describeThrowable(\Throwable $e, int $depth = 0): array
    {
        $message = $e instanceof QueryException ? self::scrubQueryMessage($e->getMessage(), $e->getSql()) : self::scrubMessage($e->getMessage());

        return [
            'class' => $e::class,
            'message' => $message,
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => self::traceWithoutArguments($e),
            // A cause chain is bounded: a cycle cannot loop and a long chain cannot flood the log.
            'previous' => $e->getPrevious() !== null && $depth < 10 ? self::describeThrowable($e->getPrevious(), $depth + 1) : null,
        ];
    }

    /**
     * "file:line Class::function" per frame and nothing else, as Monolog's normalizer does: never getTraceAsString,
     * which prints argument values. (zend.exception_ignore_args=On is set in the PHP images as a second layer.)
     */
    private static function traceWithoutArguments(\Throwable $e): string
    {
        $lines = [];
        foreach (array_slice($e->getTrace(), 0, 30) as $i => $frame) {
            $lines[] = '#'.$i.' '.($frame['file'] ?? '[internal]').':'.($frame['line'] ?? '?').' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
        }

        return implode("\n", $lines);
    }

    public static function scrubMessage(string $message): string
    {
        $message = self::scrubQueryMessage($message);
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
