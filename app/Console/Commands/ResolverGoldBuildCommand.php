<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Services\GoldPairSampler;
use App\Domain\Sources\Data\IngestRegion;
use Illuminate\Console\Command;

/**
 * Build the entity-resolution gold set (ENTITY-RESOLUTION §6).
 *
 * Writes two files:
 *   tests/Fixtures/GoldPairs/{region}.json         — the labeled set (committed)
 *   tests/Fixtures/GoldPairs/{region}.todo.json    — pairs needing a human
 *
 * Re-running preserves existing human labels: a label a person gave is data,
 * and a sampler must never overwrite it.
 */
final class ResolverGoldBuildCommand extends Command
{
    protected $signature = 'resolver:gold-build {region=stockholm} {--negatives=400} {--human=60}';

    protected $description = 'Sample and auto-label entity-resolution pairs; surface the ambiguous ones for review';

    public function handle(GoldPairSampler $sampler): int
    {
        $region = (string) $this->argument('region');
        $dir = base_path('tests/Fixtures/GoldPairs');
        $goldPath = "{$dir}/{$region}.json";
        $todoPath = "{$dir}/{$region}.todo.json";

        if (! is_dir($dir)) {
            mkdir($dir, recursive: true);
        }

        $bounds = IngestRegion::named($region);

        $existing = $this->readLabels($goldPath);
        $result = $sampler->sample(
            $bounds->south, $bounds->west, $bounds->north, $bounds->east,
            (int) $this->option('negatives'), (int) $this->option('human'),
        );

        $pairs = [];
        foreach ($result['pairs'] as $pair) {
            $key = self::key($pair);
            // A human label always wins over a fresh auto label.
            $pairs[$key] = ($existing[$key]['labeled_by'] ?? null) === 'human' ? $existing[$key] : $pair;
        }

        // Human answers to previously-surfaced todos fold back into the set.
        $todo = [];
        foreach ($result['needs_human'] as $pair) {
            $key = self::key($pair);

            if (($existing[$key]['label'] ?? null) !== null) {
                $pairs[$key] = $existing[$key];

                continue;
            }

            $todo[] = $pair;
        }

        file_put_contents($goldPath, json_encode(array_values($pairs), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($todoPath, json_encode($todo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $matches = count(array_filter($pairs, static fn (array $p): bool => $p['label'] === 'match'));
        $human = count(array_filter($pairs, static fn (array $p): bool => $p['labeled_by'] === 'human'));

        $this->components->info(sprintf(
            '%d labeled pairs (%d match / %d distinct · %d human-labeled) → %s',
            count($pairs), $matches, count($pairs) - $matches, $human, "tests/Fixtures/GoldPairs/{$region}.json",
        ));

        if ($todo !== []) {
            $this->newLine();
            $this->components->warn(sprintf('%d ambiguous pairs need a human label.', count($todo)));
            $this->line("  Edit <options=bold>tests/Fixtures/GoldPairs/{$region}.todo.json</>, set each \"label\" to \"match\" or \"distinct\"");
            $this->line('  and "labeled_by" to "human", then move them into the gold file (or just re-run this command).');
            $this->newLine();

            $this->table(
                ['score', 'm', 'a', 'b'],
                array_map(static fn (array $p): array => [
                    $p['score'], $p['distance_m'], $p['a']['name'] ?? '?', $p['b']['name'] ?? '?',
                ], array_slice($todo, 0, 15)),
            );
        }

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> */
    private function readLabels(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $rows = json_decode((string) file_get_contents($path), true) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[self::key($row)] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $pair */
    private static function key(array $pair): string
    {
        $a = "{$pair['a']['source']}:{$pair['a']['external_id']}";
        $b = "{$pair['b']['source']}:{$pair['b']['external_id']}";

        // Order-independent: the pair (a,b) is the same pair as (b,a).
        return implode('|', [min($a, $b), max($a, $b)]);
    }
}
