<?php

declare(strict_types=1);

namespace App\Admin\Data;

use App\Models\User;

final readonly class UserRowData
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $roles,
        public string $createdAt,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roles: $user->getRoleNames()->all(),
            createdAt: $user->created_at->toISOString(),
        );
    }
}
