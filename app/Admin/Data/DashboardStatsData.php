<?php

declare(strict_types=1);

namespace App\Admin\Data;

final readonly class DashboardStatsData
{
    public function __construct(
        public int $totalUsers,
        public int $operators,
        public int $usersLast7Days,
        public int $activityLast7Days,
    ) {}
}
