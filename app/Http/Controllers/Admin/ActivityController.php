<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Queries\ListActivity;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityController extends Controller
{
    public function index(ListActivity $listActivity): Response
    {
        return Inertia::render('admin/activity', [
            'activity' => $listActivity(),
        ]);
    }
}
