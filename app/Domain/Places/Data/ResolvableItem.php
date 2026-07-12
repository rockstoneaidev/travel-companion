<?php

declare(strict_types=1);

namespace App\Domain\Places\Data;

use App\Enums\CredibilityTier;

/**
 * A source item as the resolver sees it — Places' own view of the data, so
 * the resolver never touches the Sources module's internals (conventions/01:
 * cross-module traffic goes through Contracts and Data).
 */
final readonly class ResolvableItem
{
    /** @param array<string, mixed> $payload the shared candidate format (conventions/09) */
    public function __construct(
        public string $id,
        public string $source,
        public ?string $externalId,
        public CredibilityTier $credibilityTier,
        public ?string $h3Index,
        public array $payload,
    ) {}

    public function candidate(): ResolutionCandidate
    {
        return ResolutionCandidate::fromPayload($this->payload);
    }

    public function wikidataQid(): ?string
    {
        return $this->source === 'wikidata'
            ? $this->externalId
            : ($this->payload['external_refs']['wikidata'] ?? null);
    }

    /**
     * The Wikipedia sitelink, normalised to `lang:Title` — the second explicit
     * identifier in ENTITY-RESOLUTION §3 Stage 1.
     *
     * The two sources spell it differently and neither is wrong: OSM's
     * `wikipedia=*` tag is already `sv:Storkyrkan`, while Wikidata gives the
     * article URL. Both collapse to the same key here, which is the whole point
     * — an explicit join is only explicit if both sides agree on the string.
     */
    public function wikipediaSitelink(): ?string
    {
        $raw = $this->payload['external_refs']['wikipedia'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('#^https?://([a-z\-]+)\.wikipedia\.org/wiki/(.+)$#i', $raw, $m) === 1) {
            return self::sitelink($m[1], rawurldecode($m[2]));
        }

        if (preg_match('#^([a-z\-]+):(.+)$#i', $raw, $m) === 1) {
            return self::sitelink($m[1], $m[2]);
        }

        return null;   // an unrecognised shape is not an identifier we can join on
    }

    private static function sitelink(string $lang, string $title): string
    {
        // Wikipedia titles are space/underscore-equivalent and first-letter-insensitive.
        return mb_strtolower($lang).':'.str_replace(' ', '_', trim($title));
    }
}
