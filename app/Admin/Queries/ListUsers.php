<?php

declare(strict_types=1);

namespace App\Admin\Queries;

use App\Admin\Data\UserRowData;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListUsers
{
    /**
     * @return LengthAwarePaginator<int, UserRowData>
     */
    public function __invoke(int $perPage = 25): LengthAwarePaginator
    {
        return User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate($perPage)
            ->through(UserRowData::fromModel(...));
    }
}
