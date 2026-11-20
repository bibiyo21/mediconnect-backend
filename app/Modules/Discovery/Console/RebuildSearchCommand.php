<?php

namespace App\Modules\Discovery\Console;

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Discovery\Jobs\IndexClinicSearchDocument;
use App\Modules\Discovery\Jobs\IndexDoctorSearchDocument;
use App\Modules\Discovery\Jobs\IndexSpecialtySearchDocument;
use App\Modules\Discovery\Services\DirectoryDocuments;
use App\Modules\Discovery\Services\DoctorDocuments;
use App\Modules\Discovery\Services\SearchCollections;
use App\Modules\Discovery\Services\SearchIndexer;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Specialties\Models\Specialty;
use App\Shared\Contracts\SearchEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Zero-downtime full rebuild (architecture.md §12.3): builds a new versioned
 * collection from PostgreSQL, swaps the alias, drops the old collection, then
 * replays directory events recorded during the build so nothing is lost.
 */
final class RebuildSearchCommand extends Command
{
    protected $signature = 'search:rebuild {collection : doctors, clinics, specialties or all} {--chunk=500}';

    protected $description = 'Rebuild a search collection from PostgreSQL and swap it in without downtime';

    private const EVENTS = [
        'doctors' => 'doctors.directory_changed',
        'clinics' => 'clinics.directory_changed',
        'specialties' => 'specialties.catalog_changed',
    ];

    public function handle(SearchCollections $collections, SearchEngine $engine, SearchIndexer $indexer, DoctorDocuments $doctors, DirectoryDocuments $directory): int
    {
        if (! $engine->healthy()) {
            $this->error('The search engine is not reachable.');

            return self::FAILURE;
        }
        $wanted = (string) $this->argument('collection');
        $names = $wanted === 'all' ? $collections->names() : [$wanted];
        $collections->ensureSynonymSet();
        $chunk = max(50, (int) $this->option('chunk'));

        foreach ($names as $name) {
            $started = now();
            $collection = $collections->createVersion($name);
            $count = 0;
            $ids = match ($name) {
                'doctors' => DoctorProfile::where('onboarding_status', 'ACTIVE')->orderBy('id')->pluck('id'),
                'clinics' => Clinic::where('status', 'ACTIVE')->orderBy('id')->pluck('id'),
                'specialties' => Specialty::where('is_active', true)->orderBy('id')->pluck('id'),
                default => throw new \InvalidArgumentException("Unknown search collection: {$name}"),
            };
            foreach ($ids->chunk($chunk) as $batch) {
                $documents = [];
                foreach ($batch as $id) {
                    $built = match ($name) {
                        'doctors' => $doctors->forDoctor((string) $id),
                        'clinics' => $directory->forClinic((string) $id),
                        default => $directory->forSpecialty((string) $id),
                    };
                    array_push($documents, ...$built);
                }
                $engine->upsert($collection, $documents);
                $count += count($documents);
            }

            $collections->swap($name, $collection);
            $replayed = $this->replay($name, $started);
            $this->info("{$name}: {$count} documents in {$collection}; {$replayed} changes replayed.");
        }

        return self::SUCCESS;
    }

    /** Re-queues every aggregate that changed since the build started (now written to the new collection). */
    private function replay(string $name, \DateTimeInterface $since): int
    {
        $ids = DB::table('outbox_events')->where('event_type', self::EVENTS[$name])->where('occurred_at', '>=', $since)->distinct()->pluck('aggregate_id');
        foreach ($ids as $id) {
            match ($name) {
                'doctors' => IndexDoctorSearchDocument::dispatch((string) $id),
                'clinics' => IndexClinicSearchDocument::dispatch((string) $id),
                default => IndexSpecialtySearchDocument::dispatch((string) $id),
            };
        }

        return $ids->count();
    }
}
