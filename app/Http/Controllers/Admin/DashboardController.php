<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Queries\DashboardStats;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(DashboardStats $stats): Response
    {
        return Inertia::render('admin/dashboard', [
            'stats' => $stats(),
        ]);
    }
}
