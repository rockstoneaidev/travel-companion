<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * License class of a data source (DATA-SOURCES.md §1.1, conventions/09).
 * Determines what may be persisted where — see StoragePolicy for the
 * enforcement side.
 */
enum SourceLicense: string
{
    use HasOptions;

    case Odbl = 'odbl';                          // OSM — share-alike on the derived DB (ODBL-REVIEW.md)
    case CdlaPermissive = 'cdla_permissive';     // Overture places
    case Cc0 = 'cc0';                            // Wikidata
    case CcBySa = 'cc_by_sa';                    // Wikipedia / Wikivoyage excerpts — evidence store only
    case CcBy = 'cc_by';                         // GeoNames etc.
    case LicenceOuverte = 'licence_ouverte';     // French government open data (DATAtourisme, Mérimée)
    case OpenGovernment = 'open_government';     // other government open-data licenses (e.g. K-samsök)
    case Proprietary = 'proprietary';            // Google & friends — edge-only, never persisted
    case Own = 'own';                            // curated layer, user contributions

    /** May this license's data be conflated into the ODbL geo-core? (ODBL-REVIEW §6) */
    public function isGeoCoreCompatible(): bool
    {
        return match ($this) {
            self::Odbl, self::CdlaPermissive, self::Cc0,
            self::CcBy, self::LicenceOuverte, self::OpenGovernment => true,
            self::CcBySa, self::Proprietary, self::Own => false,
        };
    }
}
