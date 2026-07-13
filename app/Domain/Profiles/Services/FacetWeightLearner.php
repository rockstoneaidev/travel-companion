<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Services;

use App\Domain\Feedback\Enums\FeedbackEvent;
use App\Domain\Privacy\Contracts\ProfilingConsent;
use App\Domain\Profiles\Models\UserTasteProfile;

/**
 * The Phase 1 learner (SCORING §4.1, profile_model_version v1): per feedback
 * event, for each facet of the place, w ← w + η(target − w) — bounded in
 * [0,1] by construction. The η table belongs HERE, not to the scorer; the
 * two version independently (§9.3).
 */
final class FacetWeightLearner
{
    public const VERSION = 'v1';

    /**
     * THE CONSENT GATE (GDPR Art. 9(2)(a), DPIA §3.2).
     *
     * It lives HERE, in the learner, and not in the two callers — because this is the
     * one place where a weight can actually move. Gating the callers would mean a
     * third caller added next year quietly re-opens the hole; gating the learner means
     * there is nowhere for a hole to be.
     *
     * Why any gate at all: we never ask for special-category data, but the taxonomy has
     * a `religious_sacred` domain and a `spiritual` facet, and this method learns a
     * weight for them. A person who keeps choosing chapels ends up with a vector that
     * is, in substance, an inferred statement about their religious belief — Art. 9
     * data by indirect deduction (C-184/20, OT v Vyriausybinė). Art. 6 consent does
     * not cover that.
     *
     * Without consent the product still WORKS, and that is the whole point: no
     * learning, α stays 0, and the user gets the honest cold-start ranking
     * (SCORING §6) rather than a confident guess we had no right to make.
     */
    public function __construct(private readonly ProfilingConsent $consent) {}

    /** @var array<string, array{target: float, eta: float}> */
    private const LEARNING = [
        'visited' => ['target' => 1.0, 'eta' => 0.30],   // the golden label
        'dismissed' => ['target' => 0.0, 'eta' => 0.25], // "not my thing" — the affordance exists to earn this weight
        'saved' => ['target' => 1.0, 'eta' => 0.15],
        'accepted' => ['target' => 1.0, 'eta' => 0.08],
        'ignored' => ['target' => 0.0, 'eta' => 0.02],
    ];

    /** Onboarding calibration rates (PRD §13.2): same rule, immediately overwritable by behavior. */
    private const CALIBRATION = ['chosen' => ['target' => 1.0, 'eta' => 0.20], 'rejected' => ['target' => 0.0, 'eta' => 0.10]];

    /** @param list<string> $facets the place's facets */
    public function apply(UserTasteProfile $profile, FeedbackEvent $event, array $facets): UserTasteProfile
    {
        if (! $this->consent->granted((int) $profile->user_id)) {
            return $profile;   // no explicit consent, no profile — see the gate above
        }

        // "Show me these again" (S6) is the one event that runs the learner
        // BACKWARDS. Routed here, inside the gate, rather than from the caller —
        // same reasoning as the gate itself: one entry point, nowhere for a future
        // caller to bypass.
        if ($event === FeedbackEvent::Undismissed) {
            return $this->retract($profile, $facets);
        }

        // Some events are recorded but teach nothing — "Didn't go" is the one
        // that matters (SCREENS S4). It must not move a weight, and it must not
        // count toward n_eff either: it is not evidence about this user's taste,
        // so it may not warm them out of cold start (SCORING §6).
        if (! $event->teachesTaste()) {
            return $profile;
        }

        $rule = self::LEARNING[$event->value];

        $weights = $profile->facet_weights;
        foreach ($facets as $facet) {
            $w = $weights[$facet] ?? 0.5;
            $weights[$facet] = round($w + $rule['eta'] * ($rule['target'] - $w), 4);
        }

        $counts = $profile->event_counts;
        $counts[$event->value] = ($counts[$event->value] ?? 0) + 1;

        $profile->forceFill([
            'facet_weights' => $weights,
            'event_counts' => $counts,
            'profile_model_version' => self::VERSION,
        ])->save();

        return $profile;
    }

    /**
     * Undo what a `dismissed` taught (SCREENS S6, "Show me these again").
     *
     * The dismiss rule has target 0, so it is purely multiplicative:
     *
     *     w' = w + η(0 − w) = (1 − η)·w
     *
     * which is exactly invertible — w = w' / (1 − η) — and that is what this does.
     * Restoring a mis-tap therefore returns the weight to the value it had before
     * the mis-tap, rather than shoving it upward with some invented positive η. The
     * user said "I didn't mean that", not "I love this".
     *
     * The honest caveat: inversion is exact only if nothing else touched the facet
     * in between. `saved`/`visited` are affine (w ← (1−η)w + η) and do not commute
     * with this, so a dismiss → keep → un-dismiss sequence lands near, not exactly
     * on, the original weight. Bounded and self-correcting, and vastly closer to the
     * truth than leaving a retracted opinion in the profile forever — which is what
     * we did before.
     *
     * `event_counts` is decremented for the same reason: n_eff drives α (SCORING §6),
     * and a retracted event is not evidence about this user, so it may not warm them
     * out of cold start.
     *
     * @param  list<string>  $facets
     */
    private function retract(UserTasteProfile $profile, array $facets): UserTasteProfile
    {
        $eta = self::LEARNING[FeedbackEvent::Dismissed->value]['eta'];

        $weights = $profile->facet_weights;
        foreach ($facets as $facet) {
            if (! isset($weights[$facet])) {
                continue;   // never learned — nothing to give back
            }

            // Clamped: an intervening `saved` can push w' above (1 − η), and a weight
            // is in [0,1] by construction. Never let an undo be the thing that breaks it.
            $weights[$facet] = round(min(1.0, $weights[$facet] / (1.0 - $eta)), 4);
        }

        $counts = $profile->event_counts;
        $dismissed = FeedbackEvent::Dismissed->value;
        $counts[$dismissed] = max(0, ($counts[$dismissed] ?? 0) - 1);

        $profile->forceFill([
            'facet_weights' => $weights,
            'event_counts' => $counts,
            'profile_model_version' => self::VERSION,
        ])->save();

        return $profile;
    }

    /**
     * One calibration pair answer (ONBOARDING.md): chosen side up, rejected
     * side down.
     *
     * @param  list<string>  $chosenFacets
     * @param  list<string>  $rejectedFacets
     */
    public function applyCalibrationPair(UserTasteProfile $profile, array $chosenFacets, array $rejectedFacets): UserTasteProfile
    {
        // Calibration is the most concentrated profiling we do — nine questions
        // designed to separate facets, one of which is `spiritual`. If anything needs
        // the gate, it is this.
        if (! $this->consent->granted((int) $profile->user_id)) {
            return $profile;
        }

        $weights = $profile->facet_weights;

        foreach (['chosen' => $chosenFacets, 'rejected' => $rejectedFacets] as $side => $facets) {
            $rule = self::CALIBRATION[$side];
            foreach ($facets as $facet) {
                $w = $weights[$facet] ?? 0.5;
                $weights[$facet] = round($w + $rule['eta'] * ($rule['target'] - $w), 4);
            }
        }

        $profile->forceFill(['facet_weights' => $weights, 'profile_model_version' => self::VERSION])->save();

        return $profile;
    }
}
