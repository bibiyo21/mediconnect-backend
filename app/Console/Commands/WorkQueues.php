<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Runs a queue worker on every queue in config('queue.priorities'), in order.
 * The local and deployed worker processes use this instead of a hand-written
 * `--queue=` list, which once missed the sms and email queues.
 */
final class WorkQueues extends Command
{
    protected $signature = 'queues:work {--stop-when-empty : Exit once the queues are empty}';

    protected $description = 'Process all application queues in priority order';

    public function handle(): int
    {
        return $this->call('queue:work', [
            '--queue' => implode(',', self::queues()),
            '--tries' => 3,
            '--backoff' => '5,30,120',
            '--timeout' => 90,
            '--stop-when-empty' => (bool) $this->option('stop-when-empty'),
        ]);
    }

    /** @return list<string> */
    public static function queues(): array
    {
        /** @var list<string> $queues */
        $queues = config('queue.priorities');

        return $queues;
    }
}
