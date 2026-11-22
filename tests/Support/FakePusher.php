<?php

namespace Tests\Support;

use Pusher\Pusher;
use Throwable;

/** A Pusher client that records terminate_connections calls (or fails) and never opens a connection. */
final class FakePusher extends Pusher
{
    /** @var list<string> */
    public array $terminated = [];

    public ?Throwable $failWith = null;

    public function __construct()
    {
        parent::__construct(REALTIME_TEST_KEY, 'test-app-secret', 'test-app-id', ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]);
    }

    public function terminateUserConnections(string $user_id): object
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->terminated[] = $user_id;

        return (object) [];
    }
}
