<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a source's data is allowed to touch (conventions/09). This is the
 * mechanism that makes the ODbL/Google boundary enforceable in code rather
 * than reviewer memory: persistence guards check it and THROW.
 */
enum StoragePolicy: string
{
    use HasOptions;

    /** May be written into the geo-core (places_core). Open data only. */
    case Persistable = 'persistable';

    /** May live in the evidence store with per-row license metadata. Never the core. */
    case EvidenceOnly = 'evidence_only';

    /** Never persisted anywhere. Fetched at recommendation time, used, discarded. Google. */
    case EdgeOnly = 'edge_only';

    /** May this data be stored at all (in any world-model table)? */
    public function isStorable(): bool
    {
        return $this !== self::EdgeOnly;
    }

    /** May this data land in places_core? */
    public function isGeoCorePersistable(): bool
    {
        return $this === self::Persistable;
    }
}
