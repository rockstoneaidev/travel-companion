<?php

declare(strict_types=1);

namespace App\Domain\Places\Services;

/**
 * Every cache key in one place (conventions/12): structured, colon-delimited,
 * versioned. A typo'd key is a silent 100% miss rate. NO USER IDS — a scout
 * key with a user id destroys the shared-tile principle.
 */
final class CacheKeys
{
    public static function scout(string $sourceKey, string $h3Index, string $version): string
    {
        return "scout:{$sourceKey}:{$h3Index}:{$version}";
    }

    public static function placeHours(string $placeId): string
    {
        return "place:hours:{$placeId}";
    }

    public static function tileState(string $h3Index): string
    {
        return "tile:state:{$h3Index}";
    }

    public static function llm(string $promptVersion, string $bundleId): string
    {
        return "llm:{$promptVersion}:{$bundleId}";
    }
}
