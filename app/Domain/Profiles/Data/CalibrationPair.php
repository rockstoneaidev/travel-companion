<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Data;

/**
 * One forced-choice pair (ONBOARDING §2).
 *
 * Each pair is built to SEPARATE facets, not to be a nice question. "Tiny
 * medieval chapel with faded frescoes" against "grand national art museum" is
 * not asking which is better — it is asking which way a person leans when the
 * two pull apart, because a pair where both sides share facets teaches nothing.
 */
final readonly class CalibrationPair
{
    /**
     * @param  list<string>  $aFacets
     * @param  list<string>  $bFacets
     */
    public function __construct(
        public int $number,
        public string $aCaption,
        public array $aFacets,
        public string $bCaption,
        public array $bFacets,
        public ?string $aImage = null,
        public ?string $bImage = null,
    ) {}

    /** @return list<string> */
    public function facetsFor(string $side): array
    {
        return $side === 'a' ? $this->aFacets : $this->bFacets;
    }

    /** The other side's facets — the rejected ones, which learn at half the rate. */
    public function rejectedFacetsFor(string $side): array
    {
        return $side === 'a' ? $this->bFacets : $this->aFacets;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'a' => ['caption' => $this->aCaption, 'image' => $this->aImage],
            'b' => ['caption' => $this->bCaption, 'image' => $this->bImage],
            // Facets are deliberately NOT sent to the client. They are the answer
            // key: a user who can see that one card is "offbeat" is no longer
            // telling us their taste, they are telling us what they want us to
            // think. The server knows which side maps to what.
        ];
    }
}
