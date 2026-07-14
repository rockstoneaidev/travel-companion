<?php

declare(strict_types=1);

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Enums\LocalAlertKind;

/**
 * "Is this headline something a traveller needs to know, and what kind?" (E39.)
 *
 * ## Why this is keywords and not the LLM — and why that is not a compromise
 *
 * NON-NEGOTIABLE #3: the LLM is never a source of facts. It is tempting to read that as
 * "the LLM may not INVENT facts", and reach for it here to *classify* — surely reading a
 * headline is safe? It is not, and the reason is subtle enough to write down.
 *
 * The fact this product would act on is *"the coast road is closed"*. That fact must come
 * from the newspaper, and it must be citable back to the newspaper. If a language model
 * decides a headline "means" a closure, the closure now rests on the model's reading, not
 * on the source — and when it is wrong (a headline about a road-closure *protest*, say) we
 * have manufactured a fact and attributed it to a paper that never asserted it. That is the
 * exact failure mode #3 exists to prevent, wearing a classifier's clothes.
 *
 * So this is deterministic and dumb on purpose. It detects that the SOURCE used the
 * vocabulary of disruption, in the region's own language, and it is transparent about
 * having done exactly and only that. The claim stays the newspaper's; we merely noticed it
 * and will cite it. A dumb detector we can point at is worth more here than a clever one we
 * cannot.
 *
 * ## Precision over recall, deliberately
 *
 * Missing a closure costs a traveller a wasted detour. Inventing one costs them their trust
 * — a companion that cries "the road is shut" when it is not is a companion you stop
 * believing, and then the true alarm is the one you ignore. So the vocabulary below is
 * narrow and unambiguous. A headline we are not sure about is not an alert.
 */
final class AlertClassifier
{
    public const VERSION = 'v1';

    /**
     * The vocabulary of disruption, per language. Ordered by kind, checked most-specific
     * first (a strike is a kind of disruption; a hazard trumps a closure).
     *
     * Kept narrow on purpose — see the class docblock. These are words that, in a local
     * news headline, mean disruption and little else. "Fire" is deliberately absent from
     * English: it is as often a restaurant name or a metaphor as a hazard, and a false
     * hazard is the most damaging false alert there is.
     *
     * @var array<string, array<string, list<string>>> lang → kind → terms
     */
    private const LEXICON = [
        'sv' => [
            'hazard' => ['översvämning', 'skogsbrand', 'stormvarning', 'evakuer'],
            'strike' => ['strejk', 'blockad'],
            'closure' => ['avstängd', 'avstängning', 'stängt', 'stängd', 'vägarbete', 'inställd'],
            'disruption' => ['försening', 'trafikstörning', 'omledning', 'begränsad framkomlighet'],
        ],
        'fr' => [
            'hazard' => ['inondation', 'incendie', 'alerte tempête', 'évacuation', 'crue'],
            'strike' => ['grève', 'mouvement social'],
            'closure' => ['fermé', 'fermeture', 'travaux', 'barré', 'coupée', 'annulé', 'annulée'],
            'disruption' => ['retard', 'perturbation', 'déviation', 'circulation perturbée'],
        ],
        'en' => [
            'hazard' => ['flood warning', 'wildfire', 'storm warning', 'evacuation'],
            'strike' => ['strike', 'walkout'],
            'closure' => ['closed', 'closure', 'roadworks', 'shut', 'cancelled', 'canceled'],
            'disruption' => ['delays', 'diversion', 'disruption', 'suspended service'],
        ],
    ];

    /**
     * The kind of alert this text is, or null if it is not one.
     *
     * `null` is the common and correct answer — most local news is not a travel disruption,
     * and this returns null for all of it without apology.
     */
    public function classify(string $text, string $locale): ?LocalAlertKind
    {
        $lexicon = self::LEXICON[$locale] ?? self::LEXICON['en'];
        $haystack = mb_strtolower($text);

        // Most-severe first: a headline that is both a strike AND a closure ("station shut
        // by strike") is a strike — the cause is the thing that will still be true tomorrow.
        foreach (['hazard', 'strike', 'closure', 'disruption'] as $kind) {
            foreach ($lexicon[$kind] as $term) {
                if (str_contains($haystack, $term)) {
                    return LocalAlertKind::from($kind);
                }
            }
        }

        return null;
    }
}
