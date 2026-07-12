<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use Illuminate\Database\Eloquent\Model;

/** One calibration answer (ONBOARDING §1). Append-only; a skip is an answer too. */
final class ProfileSignal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'chosen_facets' => 'array',
            'rejected_facets' => 'array',
        ];
    }
}
