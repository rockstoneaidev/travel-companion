<?php

declare(strict_types=1);

namespace App\Admin\Queries;

use App\Admin\Data\ActivityRowData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Activitylog\Models\Activity;

final class ListActivity
{
    /**
     * @return LengthAwarePaginator<int, ActivityRowData>
     */
    public function __invoke(int $perPage = 50): LengthAwarePaginator
    {
        return Activity::query()
            ->with('causer')
            ->latest('id')
            ->paginate($perPage)
            ->through(ActivityRowData::fromModel(...));
    }
}
