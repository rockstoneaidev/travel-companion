<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The machine reviewer's first finding, on its first honest run.
 *
 * Auditing the 149 items a human had already approved, the deterministic gate refused
 * a third of them for a reason nobody had looked for: **CC BY-SA evidence with no
 * attribution on the row.**
 *
 *   datatourisme   licence_ouverte   attribution: yes   x40
 *   merimee        licence_ouverte   attribution: yes   x32
 *   wikivoyage     CC BY-SA 4.0      attribution: NO    x40   ← every single one
 *
 * CC BY-SA does not ask for attribution, it REQUIRES it (ODBL-REVIEW §6, conventions/09).
 * We were holding — and serving — forty quoted excerpts from Wikivoyage with nothing
 * on the row saying who wrote them. That is a licence breach, and it is exactly the
 * kind a human reviewer cannot catch: the attribution is not in the claim they read,
 * it is in a field they never see.
 *
 * The rows are legacy: no code in the repo produces `wikivoyage` evidence any more, so
 * there is no source to fix — only rows to repair. Idempotent: it only touches evidence
 * entries that have no attribution, so re-running changes nothing.
 */
return new class extends Migration
{
    private const ATTRIBUTION = 'Wikivoyage contributors, CC BY-SA 4.0';

    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE curated_items ci
            SET evidence = repaired.evidence
            FROM (
                SELECT
                    ci2.id,
                    jsonb_agg(
                        CASE
                            WHEN e->>'attribution' IS NULL
                             AND coalesce(e->>'source_type', e->>'source') = 'wikivoyage'
                            THEN e || jsonb_build_object('attribution', ?::text)
                            ELSE e
                        END
                        ORDER BY ord
                    ) AS evidence
                FROM curated_items ci2,
                     LATERAL jsonb_array_elements(ci2.evidence) WITH ORDINALITY AS t(e, ord)
                WHERE jsonb_typeof(ci2.evidence) = 'array'
                GROUP BY ci2.id
            ) AS repaired
            WHERE ci.id = repaired.id
              AND ci.evidence IS DISTINCT FROM repaired.evidence
        SQL, [self::ATTRIBUTION]);
    }

    public function down(): void
    {
        // Deliberately not reversible. Removing an attribution we are legally required
        // to carry is not a rollback, it is the bug.
    }
};
