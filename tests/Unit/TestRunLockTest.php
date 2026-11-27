<?php

/*
 * The test-run lock (tests/Support/concurrency.php) keeps one pest process on mediconnect_test at a time. A second run exits before it
 * touches the database, so the proof of that exit is run from the shell (see the increment 5.6-3t report). This file proves the lock is
 * held by this process: another session cannot take it, and taking it again in-process is a no-op.
 */

it('holds the test-run lock so another session cannot take it', function () {
    acquire_test_run_lock();

    $cfg = config('database.connections.pgsql_owner');
    $other = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['sslmode'] ?? 'prefer'),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    expect(try_test_run_lock($other))->toBeFalse();

    // Proof from the server: this process's session holds the granted advisory lock.
    $statement = $other->query(sprintf(
        "SELECT count(*) FROM pg_locks l JOIN pg_stat_activity a ON a.pid = l.pid
         WHERE l.locktype = 'advisory' AND l.granted AND l.objsubid = 1
           AND l.classid = %d AND l.objid = %d
           AND a.application_name = 'pest:%d'",
        TEST_RUN_LOCK_KEY >> 32,
        TEST_RUN_LOCK_KEY & 0xFFFFFFFF,
        getmypid(),
    ));
    if ($statement === false) {
        throw new RuntimeException('Could not query pg_locks.');
    }

    expect((int) $statement->fetchColumn())->toBe(1);
});

it('is a no-op when the lock is already held by this process', function () {
    acquire_test_run_lock();
    acquire_test_run_lock();

    // Still one session from this process: the second call opened no new connection.
    $cfg = config('database.connections.pgsql_owner');
    $other = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['sslmode'] ?? 'prefer'),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $statement = $other->query(sprintf(
        "SELECT count(*) FROM pg_stat_activity WHERE application_name = 'pest:%d'",
        getmypid(),
    ));
    if ($statement === false) {
        throw new RuntimeException('Could not query pg_stat_activity.');
    }

    expect((int) $statement->fetchColumn())->toBe(1);
});
