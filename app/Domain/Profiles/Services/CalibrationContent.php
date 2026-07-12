<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Services;

use App\Domain\Profiles\Data\CalibrationPair;

/**
 * The calibration content, versioned (ONBOARDING.md, `calibration_version`).
 *
 * Reference data in code, like the taxonomy maps: changing a pair, a caption or a
 * facet vector MINTS A NEW VERSION. It is not a config knob, because every
 * profile carries the version it was calibrated under, and a silently edited pair
 * makes every profile before it unexplainable.
 *
 * Two rules from the spec that are easy to get wrong:
 *
 *   · The scenes are GENERIC EUROPEAN, never launch-region landmarks. We are
 *     probing taste, not recognition — a person who picks the Sainte-Chapelle
 *     because they have heard of it has told us nothing.
 *   · The facet vectors never reach the client. They are the answer key.
 */
final class CalibrationContent
{
    public const VERSION = 'v1';

    /** @return list<CalibrationPair> */
    public function pairs(): array
    {
        return [
            new CalibrationPair(
                number: 1,
                aCaption: 'A tiny medieval chapel with faded frescoes, its door ajar.',
                aFacets: ['spiritual', 'architecture', 'history', 'offbeat'],
                bCaption: 'A grand national art museum, marble halls.',
                bFacets: ['art', 'educational'],
            ),
            new CalibrationPair(
                number: 2,
                aCaption: 'A morning market stall, locals queuing for one cheese.',
                aFacets: ['food_drink', 'local_life'],
                bCaption: 'A candle-lit tasting menu, seven courses.',
                bFacets: ['food_drink', 'romantic'],
            ),
            new CalibrationPair(
                number: 3,
                aCaption: 'A clifftop viewpoint, forty minutes on foot.',
                aFacets: ['nature', 'scenic', 'active'],
                bCaption: 'An old-town café terrace, watching the square.',
                bFacets: ['local_life', 'food_drink'],
            ),
            new CalibrationPair(
                number: 4,
                aCaption: "A glassblower's workshop, mid-demonstration.",
                aFacets: ['craft', 'local_life', 'educational'],
                bCaption: 'A contemporary gallery in a converted warehouse.',
                bFacets: ['art', 'offbeat'],
            ),
            new CalibrationPair(
                number: 5,
                aCaption: 'A ruined hilltop castle. No ticket booth, big views.',
                aFacets: ['history', 'scenic', 'active', 'offbeat'],
                bCaption: "A writer's preserved home, rooms as they were left.",
                bFacets: ['history', 'educational'],
            ),
            new CalibrationPair(
                number: 6,
                aCaption: 'A harbour walk at golden hour.',
                aFacets: ['scenic', 'romantic'],
                bCaption: "A live trio in a cellar bar, locals' night out.",
                bFacets: ['art', 'local_life'],
            ),
            new CalibrationPair(
                number: 7,
                aCaption: 'A botanical garden — greenhouse and picnic lawns.',
                aFacets: ['nature', 'family'],
                bCaption: 'An alley of street art, half-hidden courtyards.',
                bFacets: ['art', 'offbeat', 'active'],
            ),
            new CalibrationPair(
                number: 8,
                aCaption: 'An island swim spot and a picnic, one short ferry.',
                aFacets: ['nature', 'active', 'family'],
                bCaption: 'A guided walk: one street, five building styles.',
                bFacets: ['architecture', 'educational'],
            ),
            new CalibrationPair(
                number: 9,
                aCaption: 'A hole-in-the-wall bakery famous for a single pastry.',
                aFacets: ['food_drink', 'local_life', 'offbeat'],
                bCaption: 'A rooftop bar with the city at your feet.',
                bFacets: ['scenic', 'romantic'],
            ),
        ];
    }

    public function pair(int $number): ?CalibrationPair
    {
        foreach ($this->pairs() as $pair) {
            if ($pair->number === $number) {
                return $pair;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->pairs());
    }

    /**
     * The two practical questions (ONBOARDING §3).
     *
     * These seed FRICTION thresholds, not taste — they feed friction_penalty
     * (SCORING §5.1), never facet weights. Mixing the two would be a category
     * error: how far you will walk is not something you like.
     *
     * @return array<string, mixed>
     */
    public function practicals(): array
    {
        return [
            'walk' => [
                'question' => 'How far do you happily walk for something good?',
                'options' => [
                    ['value' => 10, 'label' => '10 minutes'],
                    ['value' => 20, 'label' => '20 minutes'],
                    ['value' => 40, 'label' => '40+ minutes'],
                ],
            ],
            'price' => [
                'question' => 'A memorable food stop is worth…',
                'options' => [
                    ['value' => 1, 'label' => 'Keep it cheap'],
                    ['value' => 2, 'label' => 'Somewhere in the middle'],
                    ['value' => 3, 'label' => "Price doesn't matter"],
                ],
            ],
        ];
    }
}
