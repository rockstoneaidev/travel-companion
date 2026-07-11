<?php

declare(strict_types=1);

namespace App\Domain\Places\Taxonomy;

/**
 * The taxonomy mapping tables' shared version (TAXONOMY §3, §8). Changing any
 * mapping row mints a new version; re-normalising is a batch reprocess over
 * the retained raw source tags, never a re-scrape.
 */
final class Taxonomy
{
    public const VERSION = 1;
}
