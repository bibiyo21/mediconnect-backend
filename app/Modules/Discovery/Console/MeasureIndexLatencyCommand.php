<?php

namespace App\Modules\Discovery\Console;

use App\Modules\Discovery\Services\SearchCollections;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Shared\Contracts\SearchEngine;
use Database\Seeders\PerformanceDatasetSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Index-update latency (increment 3.7, §11.7 target: under 5 s). Renames a
 * synthetic doctor through an ordinary model save, so the real path runs:
 * directory observer, outbox, relay job, `search` queue worker, Typesense.
 * Then polls Typesense directly until the new name is visible. A different
 * doctor each sample; the original name is restored afterwards. LOCAL only,
 * and only on the performance dataset. Needs the queue worker running.
 */
final class MeasureIndexLatencyCommand extends Command
{
    protected $signature = 'search:index-latency {--samples=20} {--timeout=30 : Seconds to wait for one sample}';

    protected $description = 'Measure how long a doctor profile change takes to reach the search index (LOCAL)';

    public function handle(SearchEngine $engine, SearchCollections $collections): int
    {
        if (! $this->laravel->environment('local') || ! PerformanceDatasetSeeder::present()) {
            $this->error('Runs on LOCAL with the performance dataset loaded (perf:dataset).');

            return self::FAILURE;
        }
        $alias = $collections->alias('doctors');
        $doctors = DoctorProfile::query()
            ->whereIn('user_id', DB::table('users')->where('email', 'like', '%@'.PerformanceDatasetSeeder::EMAIL_DOMAIN)->select('id'))
            ->inRandomOrder()->limit(max(1, (int) $this->option('samples')))->get();

        $timings = [];
        $timedOut = 0;
        foreach ($doctors as $doctor) {
            $original = $doctor->display_name;
            $marker = 'Dr. Latency '.Str::lower(Str::random(10));
            $started = hrtime(true);
            $doctor->display_name = $marker;
            DB::transaction(fn () => $doctor->save());
            $seen = $this->waitFor($engine, $alias, (string) $doctor->id, $marker, $started + (int) $this->option('timeout') * 1_000_000_000);
            if ($seen === null) {
                $timedOut++;
            } else {
                $timings[] = ($seen - $started) / 1_000_000;
            }
            $doctor->display_name = $original;
            DB::transaction(fn () => $doctor->save());
            $this->waitFor($engine, $alias, (string) $doctor->id, $original, hrtime(true) + 30_000_000_000);
        }

        sort($timings);
        $this->table(['Samples', 'Timed out', 'P50 ms', 'P95 ms', 'Max ms'], [[
            count($timings), $timedOut, self::percentile($timings, 50), self::percentile($timings, 95), $timings === [] ? '—' : round(end($timings)),
        ]]);

        return $timedOut === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return int|null hrtime when every document of the doctor shows the name, or null on timeout */
    private function waitFor(SearchEngine $engine, string $alias, string $doctorId, string $name, int $deadline): ?int
    {
        while (hrtime(true) < $deadline) {
            $result = $engine->search($alias, ['q' => '*', 'filter_by' => 'doctor_id:='.$doctorId, 'per_page' => 10, 'include_fields' => 'display_name']);
            $names = array_map(fn (array $hit) => $hit['document']['display_name'] ?? null, $result['hits'] ?? []);
            if ($names !== [] && array_unique($names) === [$name]) {
                return hrtime(true);
            }
            usleep(25_000);
        }

        return null;
    }

    /** @param  list<float>  $sorted */
    private static function percentile(array $sorted, int $p): string
    {
        if ($sorted === []) {
            return '—';
        }

        return (string) round($sorted[(int) max(0, ceil($p / 100 * count($sorted)) - 1)]);
    }
}
