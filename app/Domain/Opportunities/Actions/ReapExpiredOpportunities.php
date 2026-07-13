<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Actions;

use App\Domain\Opportunities\Data\ReapReport;
use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Sources\Services\SourceRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The reaper that `expires_at` was always asking for (conventions/03) — with the
 * step that makes it safe to have one: archive-on-expiry, never plain delete
 * (VISION.md §2, decided 2026-07-13).
 *
 * Time-bound kinds (event/ephemeral/seasonal) are moments that happened; once
 * reaped they can never be recreated, so their license-storable subset moves to
 * `archived_opportunities` first. Evergreen rows are daily materializations of
 * places that still exist in places_core — reaped without ceremony.
 *
 * Evidence is archived per row, and only from sources whose descriptor grants
 * `archivable`: indefinite retention is a narrower right than TTL'd storage.
 * Evidence from anything else — including sources no longer in the registry —
 * is dropped with the live row.
 *
 * Recommendations survive by construction: their opportunity FK nulls on delete
 * and each carries its own candidate snapshot (see the 2026-07-14 trapdoor
 * migration). Everything runs in one transaction, in the database — a reap that
 * dies mid-pass leaves either both the archive row and the deletion, or neither.
 */
final class ReapExpiredOpportunities
{
    /**
     * Days past `expires_at` before a row is reaped. Expired rows are already
     * invisible to serving (partial index, queries filter on expires_at); the
     * grace exists so that anything still holding an opportunity id — an open
     * session, a debugging eye on a trace — outlives the row it points at.
     */
    private const GRACE_DAYS = 7;

    public function __construct(
        private readonly SourceRegistry $registry,
    ) {}

    public function __invoke(): ReapReport
    {
        $cutoff = now()->subDays(self::GRACE_DAYS);

        // Every kind except Evergreen is a moment worth remembering — a kind
        // added later is archived by default rather than silently dropped.
        $kinds = array_values(array_map(
            static fn (OpportunityKind $k): string => $k->value,
            array_filter(
                OpportunityKind::cases(),
                static fn (OpportunityKind $k): bool => $k !== OpportunityKind::Evergreen,
            ),
        ));

        $sources = $this->registry->archivableSourceKeys();

        return DB::transaction(function () use ($cutoff, $kinds, $sources): ReapReport {
            $kindParams = implode(', ', array_fill(0, count($kinds), '?'));

            // Snapshot, don't reference: the place name is copied because the
            // archive must survive resolver merges; friction is left behind
            // because it is momentary and partly edge-sourced (migration note).
            $archived = DB::affectingStatement(
                "INSERT INTO archived_opportunities
                        (id, place_id, place_name, kind, status, title, summary, prompt_version,
                         window_starts_at, window_ends_at, h3_index, first_seen_at, expired_at, archived_at)
                 SELECT o.id, o.place_id, p.name, o.kind, o.status, o.title, o.summary, o.prompt_version,
                        o.window_starts_at, o.window_ends_at, o.h3_index, o.created_at, o.expires_at, NOW()
                   FROM opportunities o
                   LEFT JOIN places_core p ON p.id = o.place_id
                  WHERE o.expires_at < ?
                    AND o.kind IN ({$kindParams})
                 ON CONFLICT (id) DO NOTHING",
                [$cutoff, ...$kinds],
            );

            $evidence = 0;

            if ($sources !== []) {
                $sourceParams = implode(', ', array_fill(0, count($sources), '?'));

                $evidence = DB::affectingStatement(
                    "INSERT INTO archived_opportunity_evidence
                            (archived_opportunity_id, source, license, credibility_tier,
                             url, excerpt, attribution, retrieved_at)
                     SELECT e.opportunity_id, e.source, e.license, e.credibility_tier,
                            e.url, e.excerpt, e.attribution, e.retrieved_at
                       FROM opportunity_evidence e
                       JOIN opportunities o ON o.id = e.opportunity_id
                      WHERE o.expires_at < ?
                        AND o.kind IN ({$kindParams})
                        AND e.source IN ({$sourceParams})",
                    [$cutoff, ...$kinds, ...$sources],
                );
            }

            // All expired kinds, evergreen included. Live evidence cascades;
            // recommendations null their link and keep their trace.
            $reaped = DB::delete('DELETE FROM opportunities WHERE expires_at < ?', [$cutoff]);

            return new ReapReport(
                archived: $archived,
                archivedEvidence: $evidence,
                reaped: $reaped,
            );
        });
    }
}
