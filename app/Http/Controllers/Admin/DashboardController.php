<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Queries\DashboardStats;
use App\Cost\Queries\CostOverview;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats, CostOverview $cost): Response
    {
        return Inertia::render('admin/dashboard', [
            'stats' => $stats(),

            // Gated, not merely hidden: an operator without `costs_view` never has the
            // numbers serialised into their page props at all. "The React component
            // does not render it" is not an access control (ADMIN §3).
            'cost' => $request->user()?->can(Permission::ViewCosts->value)
                ? $cost()
                : null,
        ]);
    }
}
