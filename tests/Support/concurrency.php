<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Concurrency tests use real committed data on two sessions, so they cannot
 * run inside RefreshDatabase's transaction. Shared by every file in
 * tests/Concurrency (moved from RoleCombinationRaceTest in 4.7).
 */

/** Clean, freshly migrated test database; refuses to run against anything but *_test. */
function fresh_test_database(): void
{
    expect(config('database.connections.pgsql.database'))->toEndWith('_test');
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
    DB::purge('pgsql_second');
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
