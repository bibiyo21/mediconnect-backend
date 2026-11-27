<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Concurrency tests use real committed data on two sessions, so they cannot
 * run inside RefreshDatabase's transaction. Shared by every file in
 * tests/Concurrency (moved from RoleCombinationRaceTest in 4.7).
 */

/**
 * One test-run lock per database: a single-bigint session advisory lock. The app's own locks are (int, int) keys (LockWait 5501 to 5504),
 * a different key shape, so they cannot collide with it. The projector's hashtextextended keys share the single-bigint space, so a
 * collision is possible in principle (about 2^-64 per doctor), not ruled out.
 */
const TEST_RUN_LOCK_KEY = 21747602426123264;

/** True when this session took the run lock (pg_try_advisory_lock never waits). */
function try_test_run_lock(PDO $pdo): bool
{
    $statement = $pdo->query('SELECT pg_try_advisory_lock('.TEST_RUN_LOCK_KEY.') AS granted');
    if ($statement === false) {
        throw new RuntimeException('Could not query the test-run lock.');
    }

    return in_array($statement->fetchColumn(), [true, 't'], true);
}

/**
 * Takes the run lock on a dedicated PDO session, outside Laravel's connection manager, so DB::purge() in the race helpers cannot release
 * it. Held for the life of the process (forked children inherit it and are SIGKILLed, so they never release it). A second run exits at
 * once with a message and touches no database. Called from TestCase::createApplication, after config loads and before any migration.
 */
function acquire_test_run_lock(): void
{
    static $session = null;
    if ($session !== null) {
        return;
    }

    $cfg = config('database.connections.pgsql_owner');
    if (! str_ends_with((string) $cfg['database'], '_test')) {
        throw new RuntimeException('Refusing to take the test-run lock: the database name must end with _test.');
    }

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['sslmode'] ?? 'prefer'),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    if (! try_test_run_lock($pdo)) {
        fwrite(STDERR, "\nAnother pest run holds mediconnect_test (test-run lock is taken). This run was not started; wait for the other run to finish.\n\n");
        exit(1);
    }

    $pdo->exec("SET application_name = 'pest:".getmypid()."'");
    $session = $pdo;
}

/** Clean, freshly migrated test database; refuses to run against anything but *_test. */
function fresh_test_database(): void
{
    expect(config('database.connections.pgsql.database'))->toEndWith('_test');
    expect(config('database.connections.pgsql_owner.database'))->toEndWith('_test');
    DB::purge('pgsql_second');
    DB::connection('pgsql_owner')->statement("SET lock_timeout = '10s'");
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
}

/**
 * Runs each job in its own forked process (own database connection) and releases them together; returns, in order, ['ok' => result]
 * or ['error' => class, 'message' => ...]. The parent holds no application connection while forking; a child leaves by SIGKILL so
 * it never closes a socket it shares with the parent (a second session the test opened stays intact).
 *
 * @param  list<Closure(): mixed>  $jobs
 * @return list<array<string, mixed>>
 */
function wi_race(array $jobs): array
{
    expect(function_exists('pcntl_fork'))->toBeTrue();
    DB::purge('pgsql');
    DB::purge('pgsql_owner');

    $children = [];
    foreach ($jobs as $job) {
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            wi_child($job, $childSocket, $parentSocket);
        }
        fclose($childSocket);
        $children[] = [$pid, $parentSocket];
    }
    foreach ($children as [, $socket]) {
        fwrite($socket, 'g');
    }

    $results = [];
    foreach ($children as [$pid, $socket]) {
        $results[] = json_decode((string) stream_get_contents($socket), true) ?? ['error' => 'no result'];
        fclose($socket);
        pcntl_waitpid($pid, $status);
    }

    return $results;
}

/**
 * The forked child: waits for the start signal, runs the job, reports, and always ends by SIGKILL, whatever happens, so the child
 * never goes on to run the rest of the test suite and never closes a socket it shares with the parent.
 *
 * @param  resource  $childSocket
 * @param  resource  $parentSocket
 */
function wi_child(Closure $job, $childSocket, $parentSocket): never
{
    try {
        fclose($parentSocket);
        fread($childSocket, 1);
        $started = microtime(true);
        try {
            $out = ['ok' => $job()];
        } catch (Throwable $e) {
            $out = ['error' => $e::class, 'message' => $e->getMessage()];
        }
        $out['elapsed'] = microtime(true) - $started;
        @fwrite($childSocket, (string) json_encode($out));
    } catch (Throwable) {
        // nothing to report to; the parent sees "no result"
    } finally {
        posix_kill(posix_getpid(), SIGKILL);
    }
    exit(0);
}

/** @param list<array<string, mixed>> $results */
function wi_outcomes(array $results): array
{
    return array_map(fn ($r) => $r['error'] ?? 'ok', $results);
}

/**
 * Forks the racers, runs $parent while they work (the parent keeps its second session, which the children inherit but never touch),
 * and returns the children's results.
 *
 * @param  list<Closure(): mixed>  $jobs
 * @return list<array<string, mixed>>
 */
function wi_race_with_parent(array $jobs, Closure $parent): array
{
    DB::purge('pgsql');
    DB::purge('pgsql_owner');

    $children = [];
    foreach ($jobs as $job) {
        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            wi_child($job, $childSocket, $parentSocket);
        }
        fclose($childSocket);
        $children[] = [$pid, $parentSocket];
    }
    foreach ($children as [, $socket]) {
        fwrite($socket, 'g');
    }
    $parent();

    $results = [];
    foreach ($children as [$pid, $socket]) {
        $results[] = json_decode((string) stream_get_contents($socket), true) ?? ['error' => 'no result'];
        fclose($socket);
        pcntl_waitpid($pid, $status);
    }

    return $results;
}
