<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Services;

use App\Domain\Opportunities\Enums\OpportunityKind;
use App\Domain\Opportunities\Enums\OpportunityStatus;
use App\Domain\Opportunities\Models\Opportunity;
use App\Domain\Opportunities\Models\OpportunityEvidence;
use App\Domain\Sources\Data\LocalAlert;
use App\Enums\CredibilityTier;
use App\Enums\SourceLicense;
use Illuminate\Support\Facades\DB;

/**
 * A disruption becomes an opportunity you can be warned about (E39).
 *
 * ## Why an alert is an opportunity and not a place
 *
 * A road closure is not a thing that EXISTS at a location the way a church does — it is a
 * thing that is TRUE at a location, for a while, and then stops being true. That is the
 * definition of `OpportunityKind::Ephemeral` (TAXONOMY §14.2), and modelling a closure as a
 * place would be a category error that outlived the closure: the road reopens and the
 * "place" is still in the world model, wrong forever.
 *
 * So it is an ephemeral opportunity, keyed to a real place, carrying its citation as
 * evidence, and it expires. When it expires the reaper takes it, and (because these sources
 * are not `archivable`) it is simply gone — a lifted closure is not history worth keeping.
 *
 * ## The rule that keeps this honest: no place, no alert
 *
 * The whole product is anchored to location, and a news feed gives us free text. "Route de
 * la Corniche fermée" means nothing to a map until we know WHERE the Corniche is. The
 * resolver matches the alert's text against the NAMES of places we actually know in the
 * region; if it cannot find one, the alert is DROPPED.
 *
 * That is a deliberate loss of recall in exchange for never lying about location. An alert
 * pinned to the wrong street — or to a region centroid as a lazy fallback — is worse than a
 * missing one: it sends somebody around a diversion that does not apply to them, and the
 * next real closure is the one they scroll past. We would rather miss a closure than invent
 * a place for it.
 *
 * ## And the evidence is a citation, never the article
 *
 * `excerpt` is the feed's own headline. The licence (CC-BY-SA at best, paywalled at worst)
 * permits a claim and a link, not the body — so a claim and a link is all that is ever
 * written, into the evidence store, never into `places_core`.
 */
final class MaterializeAlertOpportunities
{
    /**
     * @param  list<LocalAlert>  $alerts
     * @return list<string> opportunity ids created or refreshed
     */
    public function __invoke(array $alerts, string $regionKey): array
    {
        $ids = [];

        foreach ($alerts as $alert) {
            $place = $this->resolvePlace($alert, $regionKey);

            if ($place === null) {
                continue;   // no place, no alert — see the class docblock
            }

            $ids[] = $this->materialize($alert, $place);
        }

        return $ids;
    }

    /**
     * The place this alert is about, or null.
     *
     * Name-matching, deliberately strict: the place name has to appear, whole, in the
     * alert's text. "Katarinahissen closed for works" matches the place *Katarinahissen*;
     * it does not match on a shared substring with *Katarina kyrka*. Short names (under four
     * characters) are excluded — matching "P4" or "E4" against free text is how you pin a
     * flood warning to a petrol station.
     *
     * When two places match, the LONGEST name wins: it is the most specific, and the most
     * specific match is the least likely to be a coincidence.
     */
    private function resolvePlace(LocalAlert $alert, string $regionKey): ?object
    {
        $region = DB::table('derived_regions')->where('key', $regionKey)->first();

        if ($region === null) {
            return null;
        }

        $text = mb_strtolower($alert->searchableText());

        return DB::table('places_core')
            ->whereRaw(
                'ST_Intersects(location::geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                [$region->west, $region->south, $region->east, $region->north],
            )
            // The place name has to appear whole in the alert text. Short names are excluded:
            // matching "E4" or "P4" against free text pins a flood warning to a petrol station.
            ->whereRaw('char_length(name) >= 4')
            ->whereRaw('? LIKE \'%\' || lower(name) || \'%\'', [$text])
            // Longest name wins — the most specific match is the least likely to be chance.
            ->orderByRaw('char_length(name) DESC')
            ->select(['id', 'name', 'h3_index'])
            ->first();
    }

    private function materialize(LocalAlert $alert, object $place): string
    {
        // Idempotent per (place, kind, url): the same closure re-read from the same feed on
        // the next poll must refresh the row, not spawn a second identical warning.
        $opportunity = Opportunity::query()->updateOrCreate(
            [
                'place_id' => $place->id,
                'kind' => OpportunityKind::Ephemeral,
                'source_ref' => $alert->url,
            ],
            [
                'status' => OpportunityStatus::Scored,
                'title' => $alert->title,
                'summary' => $alert->summary,
                'h3_index' => $place->h3_index,
                'friction' => ['alert_kind' => $alert->kind->value],
                // A disruption's shelf life is short and we do not know exactly when it
                // lifts, so we hold it for a day and let the next poll renew it if the feed
                // still carries it. A closure the paper stops mentioning stops being served.
                'window_ends_at' => now()->addDay(),
                'expires_at' => now()->addDay(),
            ],
        );

        // The citation — a claim and a link, never the article body (see docblock).
        OpportunityEvidence::query()->updateOrCreate(
            ['opportunity_id' => $opportunity->id, 'source' => $alert->sourceKey, 'url' => $alert->url],
            [
                'license' => SourceLicense::CcBySa,
                'credibility_tier' => CredibilityTier::Reference,
                'excerpt' => $alert->title,
                'attribution' => $alert->attribution,
                'retrieved_at' => now(),
            ],
        );

        return $opportunity->id;
    }
}
