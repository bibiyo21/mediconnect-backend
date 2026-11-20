<?php

namespace App\Modules\Discovery\Console;

use App\Modules\Discovery\Services\CircuitBreaker;
use App\Modules\Discovery\Services\DoctorSearch;
use Illuminate\Console\Command;

/**
 * Scores doctor search against the judged queries (architecture.md §12.4):
 * precision at 5 and 10 (normalized by the number of relevant doctors), mean
 * reciprocal rank, and exact-name-first checks. Results are as measured on the
 * current index; run after `db:seed --class=SearchRankingFixtureSeeder` and
 * `search:rebuild doctors` locally.
 */
final class EvaluateSearchCommand extends Command
{
    protected $signature = 'search:evaluate {--min-p5=0 : Fail when mean precision at 5 is below this (0-1)} {--json : Print the summary as JSON}';

    protected $description = 'Score doctor search ranking against the judged queries';

    public function handle(DoctorSearch $search, CircuitBreaker $breaker): int
    {
        $summary = self::evaluate($search, $breaker);
        if ($this->option('json')) {
            $this->line((string) json_encode($summary));
        } else {
            $this->table(['Query', 'P@5', 'P@10', 'RR', 'First ok', 'Top 3'], array_map(fn (array $r) => [$r['q'], $r['p5'], $r['p10'], $r['rr'], $r['first_ok'] === null ? '—' : ($r['first_ok'] ? 'yes' : 'NO'), implode(', ', array_slice($r['returned'], 0, 3))], $summary['queries']));
            $this->info(sprintf('Mean P@5 %.3f · mean P@10 %.3f · MRR %.3f · exact-name first %d/%d', $summary['mean_p5'], $summary['mean_p10'], $summary['mrr'], $summary['first_passed'], $summary['first_total']));
        }

        return $summary['mean_p5'] < (float) $this->option('min-p5') || $summary['degraded'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{queries: list<array{q: string, p5: float, p10: float, rr: float, first_ok: bool|null, returned: list<string>}>, mean_p5: float, mean_p10: float, mrr: float, first_passed: int, first_total: int, degraded: bool}
     */
    public static function evaluate(DoctorSearch $search, CircuitBreaker $breaker): array
    {
        $breaker->reset();
        /** @var list<array{q: string, relevant: list<string>, first?: string}> $judged */
        $judged = require database_path('seeders/data/search-judgments.php');
        $rows = [];
        $degraded = false;
        foreach ($judged as $j) {
            $result = $search->search(['q' => $j['q'], 'per_page' => 10, 'page' => 1, 'nocache' => microtime(true)]);
            $degraded = $degraded || $result['degraded'];
            $names = array_map(fn (array $r) => (string) $r['display_name'], $result['results']);
            $relevant = $j['relevant'];
            $hits = fn (int $k) => count(array_intersect(array_slice($names, 0, $k), $relevant));
            $rank = null;
            foreach ($names as $i => $n) {
                if (in_array($n, $relevant, true)) {
                    $rank = $i + 1;
                    break;
                }
            }
            $rows[] = [
                'q' => $j['q'],
                'p5' => round($hits(5) / min(5, count($relevant)), 3),
                'p10' => round($hits(10) / min(10, count($relevant)), 3),
                'rr' => $rank === null ? 0.0 : round(1 / $rank, 3),
                'first_ok' => isset($j['first']) ? (($names[0] ?? null) === $j['first']) : null,
                'returned' => $names,
            ];
        }
        $n = max(1, count($rows));
        $firsts = array_filter($rows, fn (array $r) => $r['first_ok'] !== null);

        return [
            'queries' => $rows,
            'mean_p5' => round(array_sum(array_column($rows, 'p5')) / $n, 3),
            'mean_p10' => round(array_sum(array_column($rows, 'p10')) / $n, 3),
            'mrr' => round(array_sum(array_column($rows, 'rr')) / $n, 3),
            'first_passed' => count(array_filter($firsts, fn (array $r) => $r['first_ok'] === true)),
            'first_total' => count($firsts),
            'degraded' => $degraded,
        ];
    }
}
