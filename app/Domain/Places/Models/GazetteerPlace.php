<?php

declare(strict_types=1);

namespace App\Domain\Places\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named place in the global gazetteer (PLAN-DRIVEN-INGESTION §3) — a search index entry,
 * not an explorable place. Name, coordinates, and enough to rank and disambiguate; nothing
 * you could recommend. See the migration for why it lives apart from `places_core`.
 *
 * @property int $id
 * @property int $osm_id
 * @property string $name
 * @property string $place_type
 * @property int|null $population
 * @property string $country_code
 * @property string|null $admin_label
 */
final class GazetteerPlace extends Model
{
    protected $table = 'gazetteer_places';

    protected $guarded = [];
}
