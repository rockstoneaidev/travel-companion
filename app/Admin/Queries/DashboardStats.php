<?php

declare(strict_types=1);

namespace App\Admin\Queries;

use App\Admin\Data\DashboardStatsData;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class DashboardStats
{
    public function __invoke(): DashboardStatsData
    {
        return new DashboardStatsData(
            totalUsers: User::query()->count(),
            operators: User::query()->whereHas('roles')->count(),
            usersLast7Days: User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            activityLast7Days: Activity::query()->where('created_at', '>=', now()->subDays(7))->count(),
        );
    }
}
