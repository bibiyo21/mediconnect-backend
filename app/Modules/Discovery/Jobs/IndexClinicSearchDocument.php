<?php

namespace App\Modules\Discovery\Jobs;

use App\Modules\Discovery\Services\DirectoryDocuments;
use App\Modules\Discovery\Services\SearchIndexer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds one clinic's location documents from PostgreSQL (architecture.md §12.3).
 * Never uses an event payload, so it is idempotent and order-independent
 * (concurrency scenario 10). Unique per id until it starts: changes made while
 * it waits are picked up by the run itself; changes after it starts queue a new one.
 */
final class IndexClinicSearchDocument implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $clinicId)
    {
        $this->onQueue('search');
    }

    public function uniqueId(): string
    {
        return $this->clinicId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function handle(DirectoryDocuments $builder, SearchIndexer $indexer): void
    {
        $indexer->replace('clinics', 'clinic_id', $this->clinicId, $builder->forClinic($this->clinicId));
    }
}
