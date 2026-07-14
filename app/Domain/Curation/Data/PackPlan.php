<?php

declare(strict_types=1);

namespace App\Domain\Curation\Data;

/**
 * How many approved items a region's pack is aiming for (CURATION §4).
 *
 * ===========================================================================
 *  One plan, in one place, because three copies of it disagreed.
 * ===========================================================================
 *
 * These numbers are not arbitrary — they are nights-weighted, and CURATION §4 argues
 * each one. But they lived as a `const TARGETS` on a CONSOLE COMMAND, which had two
 * consequences:
 *
 *   · the admin console reached into `CurationDraftPackCommand` to read them, so an
 *     HTTP controller depended on a console command — the wrong way round;
 *   · and `curation:publish` did not read them at all. It carried its own flat
 *     `TARGET_APPROVED = 25` for every region, so it refused to publish Bordeaux (23
 *     approved, target 20) and Lyon (20 approved, target 20) — two packs that had
 *     MET their target and were complete. The plan said one thing and the gate said
 *     another, and the gate won.
 *
 * A number that decides whether work ships is a domain fact, not a detail of whichever
 * command happened to need it first.
 */
final readonly class PackPlan
{
    /**
     * Nights-weighted (CURATION §4). Paris takes two stays and gets the deepest pack;
     * the one-night cities get the shallowest.
     */
    private const TARGETS = [
        'paris' => 40,
        'nice' => 30,
        'nantes' => 30,
        'dijon' => 25,
        'lyon' => 20,
        'bordeaux' => 20,
        'toulouse' => 20,
        'stockholm' => 30,
    ];

    /**
     * A region with no entry is a region nobody has planned a pack for, and 20 is the
     * floor the plan uses for its shallowest city — enough curated voice to be worth
     * shipping, and not so much that an unplanned region silently sets its own bar.
     */
    private const DEFAULT_TARGET = 20;

    public static function targetFor(string $regionKey): int
    {
        return self::TARGETS[$regionKey] ?? self::DEFAULT_TARGET;
    }

    /** @return array<string, int> */
    public static function all(): array
    {
        return self::TARGETS;
    }
}
