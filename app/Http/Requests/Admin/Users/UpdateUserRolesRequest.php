<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Users;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::ManageUserRoles->value) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::enum(Role::class)],
        ];
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return array_map(
            fn (string $role): Role => Role::from($role),
            $this->validated('roles', []),
        );
    }
}
