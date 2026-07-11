<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Admin\Queries\ListUsers;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(ListUsers $listUsers): Response
    {
        return Inertia::render('admin/users', [
            'users' => $listUsers(),
            'roleOptions' => Role::options(),
        ]);
    }
}
