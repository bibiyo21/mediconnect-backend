<?php

use App\Shared\Logging\RedactSensitiveData;
use App\Shared\Logging\SentryScrubber;
use Illuminate\Database\QueryException;
use Monolog\Level;
use Monolog\LogRecord;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;

/*
 * A QueryException message inlines the bindings into the SQL and carries PostgreSQL's DETAIL line (the
 * offending values). Logs and Sentry keep the SQLSTATE and the statement with placeholders only.
 */

function query_exception(): QueryException
{
    $pdo = new PDOException("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(jane.private@example.com) already exists.");

    return new QueryException('pgsql', 'insert into users (email, mobile_e164) values (?, ?)', ['jane.private@example.com', '+639171234567'], $pdo, ['host' => 'postgres', 'database' => 'mediconnect']);
}

it('rewrites a query exception message to SQLSTATE and the statement without bindings', function () {
    $e = query_exception();

    expect($e->getMessage())->toContain('jane.private@example.com'); // the raw message is the problem

    $clean = RedactSensitiveData::scrubQueryMessage($e->getMessage(), $e->getSql());

    expect($clean)->toBe('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_unique" (SQL: insert into users (email, mobile_e164) values (?, ?))')
        ->and($clean)->not->toContain('jane.private')->not->toContain('+63917')->not->toContain('DETAIL')->not->toContain('Host');
});

it('drops the value of an invalid input syntax error', function () {
    $message = 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "not-a-private-uuid" (Connection: pgsql, SQL: select * from users where id = not-a-private-uuid)';

    expect(RedactSensitiveData::scrubQueryMessage($message))->toBe('SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid');
});

it('leaves other messages alone', function () {
    expect(RedactSensitiveData::scrubQueryMessage('Something failed: SQL is fine'))->toBe('Something failed: SQL is fine');
});

it('keeps bindings out of log records, in the message and in an exception context', function () {
    $e = query_exception();
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Error, $e->getMessage(), ['exception' => $e, 'nested' => ['previous' => $e]]);

    $out = (new RedactSensitiveData)($record);
    $json = json_encode([$out->message, $out->context]);

    expect($json)->not->toContain('jane.private')->not->toContain('639171234567')->not->toContain('DETAIL')
        ->and($out->context['exception']['message'])->toContain('SQLSTATE[23505]')->toContain('(SQL: insert into users (email, mobile_e164) values (?, ?))')
        ->and($out->context['exception']['class'])->toBe(QueryException::class);
});

it('sends Sentry the SQLSTATE and statement with placeholders only', function () {
    $e = query_exception();
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag($e)]);

    $out = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $e]));
    $value = $out->getExceptions()[0]->getValue();

    expect($value)->toContain('SQLSTATE[23505]')->toContain('(SQL: insert into users (email, mobile_e164) values (?, ?))')
        ->and($value)->not->toContain('jane.private')->not->toContain('639171234567');

    // Without a hint the message is still cleaned (no statement, no bindings).
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag($e)]);
    $bare = SentryScrubber::beforeSend($event)->getExceptions()[0]->getValue();
    expect($bare)->not->toContain('jane.private')->not->toContain('639171234567')->toContain('SQLSTATE[23505]');
});

it('replaces any quoted value after a colon in the error text', function () {
    $message = 'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input value for enum appointment_status: "secret-input" (Connection: pgsql, SQL: select 1)';
    $other = 'SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(5): "jane.private@example.com" extra';

    expect(RedactSensitiveData::scrubQueryMessage($message))->toBe('SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input value for enum appointment_status: "[REDACTED]"')
        ->and(RedactSensitiveData::scrubQueryMessage($other))->not->toContain('jane.private')->toContain('"[REDACTED]"');

    // A constraint name is not user data and stays.
    expect(RedactSensitiveData::scrubQueryMessage(query_exception()->getMessage()))->toContain('"users_email_unique"');
});

it('keeps a scrubbed trace and the scrubbed causes in the flattened log shape', function () {
    $e = query_exception();
    $out = (new RedactSensitiveData)(new LogRecord(new DateTimeImmutable, 'test', Level::Error, 'failed', ['exception' => $e]));
    $shape = $out->context['exception'];

    expect(array_keys($shape))->toBe(['class', 'message', 'code', 'file', 'trace', 'previous'])
        ->and($shape['trace'])->toBeString()->not->toBeEmpty()
        ->and($shape['previous']['class'])->toBe(PDOException::class)
        ->and($shape['previous']['message'])->toContain('SQLSTATE[23505]')->not->toContain('jane.private')->not->toContain('DETAIL')
        ->and($shape['previous']['previous'])->toBeNull()
        ->and(json_encode($out->context))->not->toContain('jane.private');
});

it('walks the causes of any exception, so a RuntimeException wrapping a query error is just as clean', function () {
    $outer = new RuntimeException('Could not save the appointment', 0, query_exception());

    $record = (new RedactSensitiveData)(new LogRecord(new DateTimeImmutable, 'test', Level::Error, 'failed', ['exception' => $outer, 'deep' => ['again' => $outer]]));
    $shape = $record->context['exception'];

    expect($shape['class'])->toBe(RuntimeException::class)->and($shape['message'])->toBe('Could not save the appointment')
        ->and($shape['previous']['class'])->toBe(QueryException::class)
        ->and($shape['previous']['message'])->toContain('SQLSTATE[23505]')->toContain('(SQL: insert into users (email, mobile_e164) values (?, ?))')
        ->and($shape['previous']['previous']['class'])->toBe(PDOException::class)
        ->and($shape['previous']['previous']['message'])->not->toContain('DETAIL')
        ->and($record->context['deep']['again']['previous']['class'])->toBe(QueryException::class)
        ->and(json_encode($record->context))->not->toContain('jane.private')->not->toContain('639171234567');
});

/** A function that throws, with a sensitive value among its arguments. */
function failing_with_argument(string $phone): never
{
    throw new RuntimeException('could not send');
}

it('logs the trace without call arguments (like Monolog, never getTraceAsString)', function () {
    ini_set('zend.exception_ignore_args', '0'); // make sure this run really captures arguments
    try {
        failing_with_argument('+639171234567');
    } catch (RuntimeException $e) {
        // fall through
    } finally {
        ini_restore('zend.exception_ignore_args');
    }

    // Where PHP captures arguments the raw exception carries the value; some environments (CI images)
    // ignore the runtime ini change, and then the leak cannot occur at all. The formatter is checked either way.
    if (($e->getTrace()[0]['args'][0] ?? null) !== null) {
        expect($e->getTrace()[0]['args'][0])->toBe('+639171234567');
    }

    $record = (new RedactSensitiveData)(new LogRecord(new DateTimeImmutable, 'test', Level::Error, 'failed', ['exception' => $e]));
    $trace = $record->context['exception']['trace'];

    expect($trace)->toContain('failing_with_argument')->toMatch('/#0 .+QueryExceptionScrubbingTest\.php:\d+ failing_with_argument/')
        ->and(json_encode($record->context))->not->toContain('639171234567')
        ->and($trace)->not->toContain('Object(');
});

it('keeps the PHP images from putting arguments into exception traces at all', function () {
    expect(file_get_contents(dirname(base_path()).'/infrastructure/docker/php/php.ini') ?: 'unreadable')->toContain('zend.exception_ignore_args = On');
})->skip(fn () => ! is_readable(dirname(base_path()).'/infrastructure/docker/php/php.ini'), 'infrastructure not mounted');
