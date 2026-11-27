<?php

namespace Tests\Support;

use Pusher\ApiErrorException;
use Pusher\Pusher;
use Throwable;

/** A Pusher client that records terminate_connections calls (or fails) and never opens a connection. */
final class FakePusher extends Pusher
{
    /** @var list<string> */
    public array $terminated = [];

    public ?Throwable $failWith = null;

    /** @var array<string, int> occupied channel name => user_count, as Reverb's GET /channels reports it */
    public array $channels = [];

    /** @var array<string, list<string>> channel name => member user ids (GET /channels/{c}/users) */
    public array $members = [];

    /** @var array<string, int> channel name => HTTP status its members call answers instead of the list */
    public array $memberStatus = [];

    /** Makes GET /channels fail (a 5xx, a timeout or a 401). */
    public ?Throwable $listFailWith = null;

    /** @var list<string> */
    public array $getCalls = [];

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

    /** @param  array<string, mixed>  $params */
    public function get(string $path, array $params = [], $associative = false)
    {
        $this->getCalls[] = $path;
        if ($path === '/channels') {
            if ($this->listFailWith !== null) {
                throw $this->listFailWith;
            }
            $out = [];
            foreach ($this->channels as $name => $count) {
                $out[$name] = ['user_count' => $count];
            }

            // Reverb answers an empty LIST (not an object) when nothing is occupied.
            return ['channels' => $out];
        }
        if (preg_match('#^/channels/(.+)/users$#', $path, $m) === 1) {
            if (isset($this->memberStatus[$m[1]])) {
                throw new ApiErrorException('error', $this->memberStatus[$m[1]]);
            }

            return ['users' => array_map(fn (string $id) => ['id' => $id], $this->members[$m[1]] ?? [])];
        }

        return [];
    }
}
