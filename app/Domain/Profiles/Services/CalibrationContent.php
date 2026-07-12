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
 *
 * ---------------------------------------------------------------------------
 *  v2 — THE IMAGES (public/images/calibration/)
 * ---------------------------------------------------------------------------
 *
 * v1 shipped as text on paper-stripe placeholders. But "which of these two pulls
 * you in?" is a VISUAL question, and answering it from prose is a different question
 * — so the pictures are content, not decoration, and their arrival mints a version.
 * The captions and facet vectors are untouched; the stimulus is not.
 *
 * They are AI-generated (Gemini), pre-generated ONCE and committed as static files.
 * Three reasons, in order of weight:
 *
 *   1. GENERIC BY CONSTRUCTION. The spec forbids landmarks, and a real photograph of
 *      a real chapel invites recognition — a person who picks the one they have heard
 *      of has told us nothing about their taste.
 *   2. ONE ART DIRECTION. Both sides of a pair are shot in the same palette, light and
 *      composition on purpose. If side A were the lovelier photograph, the pair would
 *      measure photography rather than taste, and the answer would still land in a
 *      profile claiming to know you.
 *   3. FROZEN. Same pair, same two pictures, for every user and every future user.
 *      An image chosen at request time would make `calibration_version` a lie.
 *
 * The bright line: generated imagery is acceptable for a STIMULUS and never for a
 * PLACE. Illustrating a real place with a model's guess at what it looks like is the
 * LLM asserting a fact about the world (CLAUDE.md, non-negotiable 3) — `place_images`
 * carries photographs of things that exist, with attribution, and always will.
 */
final class CalibrationContent
{
    public const VERSION = 'v2';

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
                aImage: '/images/calibration/pair-1-a.jpg',
                bImage: '/images/calibration/pair-1-b.jpg',
            ),
            new CalibrationPair(
                number: 2,
                aCaption: 'A morning market stall, locals queuing for one cheese.',
                aFacets: ['food_drink', 'local_life'],
                bCaption: 'A candle-lit tasting menu, seven courses.',
                bFacets: ['food_drink', 'romantic'],
                aImage: '/images/calibration/pair-2-a.jpg',
                bImage: '/images/calibration/pair-2-b.jpg',
            ),
            new CalibrationPair(
                number: 3,
                aCaption: 'A clifftop viewpoint, forty minutes on foot.',
                aFacets: ['nature', 'scenic', 'active'],
                bCaption: 'An old-town café terrace, watching the square.',
                bFacets: ['local_life', 'food_drink'],
                aImage: '/images/calibration/pair-3-a.jpg',
                bImage: '/images/calibration/pair-3-b.jpg',
            ),
            new CalibrationPair(
                number: 4,
                aCaption: "A glassblower's workshop, mid-demonstration.",
                aFacets: ['craft', 'local_life', 'educational'],
                bCaption: 'A contemporary gallery in a converted warehouse.',
                bFacets: ['art', 'offbeat'],
                aImage: '/images/calibration/pair-4-a.jpg',
                bImage: '/images/calibration/pair-4-b.jpg',
            ),
            new CalibrationPair(
                number: 5,
                aCaption: 'A ruined hilltop castle. No ticket booth, big views.',
                aFacets: ['history', 'scenic', 'active', 'offbeat'],
                bCaption: "A writer's preserved home, rooms as they were left.",
                bFacets: ['history', 'educational'],
                aImage: '/images/calibration/pair-5-a.jpg',
                bImage: '/images/calibration/pair-5-b.jpg',
            ),
            new CalibrationPair(
                number: 6,
                aCaption: 'A harbour walk at golden hour.',
                aFacets: ['scenic', 'romantic'],
                bCaption: "A live trio in a cellar bar, locals' night out.",
                bFacets: ['art', 'local_life'],
                aImage: '/images/calibration/pair-6-a.jpg',
                bImage: '/images/calibration/pair-6-b.jpg',
            ),
            new CalibrationPair(
                number: 7,
                aCaption: 'A botanical garden — greenhouse and picnic lawns.',
                aFacets: ['nature', 'family'],
                bCaption: 'An alley of street art, half-hidden courtyards.',
                bFacets: ['art', 'offbeat', 'active'],
                aImage: '/images/calibration/pair-7-a.jpg',
                bImage: '/images/calibration/pair-7-b.jpg',
            ),
            new CalibrationPair(
                number: 8,
                aCaption: 'An island swim spot and a picnic, one short ferry.',
                aFacets: ['nature', 'active', 'family'],
                bCaption: 'A guided walk: one street, five building styles.',
                bFacets: ['architecture', 'educational'],
                aImage: '/images/calibration/pair-8-a.jpg',
                bImage: '/images/calibration/pair-8-b.jpg',
            ),
            new CalibrationPair(
                number: 9,
                aCaption: 'A hole-in-the-wall bakery famous for a single pastry.',
                aFacets: ['food_drink', 'local_life', 'offbeat'],
                bCaption: 'A rooftop bar with the city at your feet.',
                bFacets: ['scenic', 'romantic'],
                aImage: '/images/calibration/pair-9-a.jpg',
                bImage: '/images/calibration/pair-9-b.jpg',
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
