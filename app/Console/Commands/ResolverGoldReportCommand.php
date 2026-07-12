<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Contracts\ResolvableItems;
use App\Domain\Places\Data\ResolutionCandidate;
use App\Domain\Places\Data\ResolvableItem;
use App\Domain\Places\Enums\MatchBand;
use App\Domain\Places\Services\MatchScorer;
use Illuminate\Console\Command;

/**
 * Precision/recall for the resolver, keyed by resolver_version
 * (ENTITY-RESOLUTION §6).
 *
 * The interesting number is FALSE MERGES: a duplicate is annoying, a false
 * merge is corruption. The spec's bar is zero known false merges at v1
 * thresholds, so this command exits non-zero if it finds any — it is a gate,
 * not a dashboard.
 */
final class ResolverGoldReportCommand extends Command
{
    protected $signature = 'resolver:gold-report {region=stockholm} {--rv= : Score against a specific resolver_version (default: active)}';

    protected $description = 'Precision/recall of the resolver against the labeled gold set';

    public function handle(ResolvableItems $items): int
    {
        $region = (string) $this->argument('region');
        $version = (string) ($this->option('rv') ?: config('resolver.version'));
        $constants = config("resolver.versions.{$version}");

        if ($constants === null) {
            $this->components->error("Unknown resolver_version \"{$version}\".");

            return self::FAILURE;
        }

        // Score with THAT version's constants, not the active ones — the whole
        // point of versioning is that an old decision stays reproducible.
        $scorer = new MatchScorer($constants);
        $path = base_path("tests/Fixtures/GoldPairs/{$region}.json");

        if (! is_file($path)) {
            $this->components->error("No gold set at tests/Fixtures/GoldPairs/{$region}.json — run `resolver:gold-build {$region}` first.");

            return self::FAILURE;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents($path), true) ?: [];
        $labeled = array_values(array_filter($rows, static fn (array $r): bool => $r['label'] !== null));

        if ($labeled === []) {
            $this->components->error('Gold set has no labeled pairs.');

            return self::FAILURE;
        }

        $byKey = $this->itemsByKey($items, $labeled);

        $tp = $fp = $tn = $fn = 0;
        $falseMerges = [];
        $missed = [];

        foreach ($labeled as $row) {
            $a = $byKey["{$row['a']['source']}:{$row['a']['external_id']}"] ?? null;
            $b = $byKey["{$row['b']['source']}:{$row['b']['external_id']}"] ?? null;

            if ($a === null || $b === null) {
                continue;   // the item has since left the world model
            }

            $scored = $scorer->score(
                ResolutionCandidate::fromPayload($a->payload),
                ResolutionCandidate::fromPayload($b->payload),
            );

            // "Predicted match" means the resolver would auto-merge without a
            // human. Review band is explicitly NOT a merge — that is the point
            // of having it.
            $predictedMerge = $scored['band'] === MatchBand::High;
            $isMatch = $row['label'] === 'match';

            match (true) {
                $predictedMerge && $isMatch => $tp++,
                $predictedMerge && ! $isMatch => [$fp++, $falseMerges[] = [...$row, 'scored' => $scored['score']]],
                ! $predictedMerge && $isMatch => [$fn++, $missed[] = [...$row, 'scored' => $scored['score']]],
                default => $tn++,
            };
        }

        $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : 1.0;
        $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : 1.0;
        $f1 = $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        $this->newLine();
        $this->components->info(sprintf(
            'resolver %s · auto_merge %.2f · review %.2f · %d labeled pairs',
            $version, $constants['bands']['auto_merge'], $constants['bands']['review'], count($labeled),
        ));

        $this->table(
            ['metric', 'value'],
            [
                ['true merges (TP)', $tp],
                ['false merges (FP)', $fp],
                ['missed merges (FN)', $fn],
                ['correct non-merges (TN)', $tn],
                ['precision', sprintf('%.4f', $precision)],
                ['recall', sprintf('%.4f', $recall)],
                ['F1', sprintf('%.4f', $f1)],
            ],
        );

        if ($missed !== []) {
            $this->components->warn(sprintf('%d matches the resolver did not auto-merge (safe: they are duplicates, not corruption)', count($missed)));
            foreach (array_slice($missed, 0, 10) as $m) {
                $this->line(sprintf('  %.4f  %s ↔ %s', $m['scored'], $m['a']['name'] ?? '?', $m['b']['name'] ?? '?'));
            }
        }

        if ($falseMerges !== []) {
            $this->newLine();
            $this->components->error(sprintf('%d FALSE MERGES at the current thresholds — this is corruption, not a rounding error:', count($falseMerges)));
            foreach ($falseMerges as $m) {
                $this->line(sprintf('  %.4f  %s ↔ %s  (%d m)', $m['scored'], $m['a']['name'] ?? '?', $m['b']['name'] ?? '?', $m['distance_m']));
            }

            return self::FAILURE;
        }

        $this->components->info("Zero false merges at {$version} thresholds.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, ResolvableItem>
     */
    private function itemsByKey(ResolvableItems $items, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            foreach (['a', 'b'] as $side) {
                $key = "{$row[$side]['source']}:{$row[$side]['external_id']}";

                if (! array_key_exists($key, $out)) {
                    $out[$key] = $items->find($row[$side]['source'], $row[$side]['external_id']);
                }
            }
        }

        return array_filter($out);
    }
}
