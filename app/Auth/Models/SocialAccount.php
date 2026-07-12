<?php

declare(strict_types=1);

namespace App\Auth\Models;

use App\Auth\Enums\SocialProvider;
use App\Models\User;
use Database\Factories\Auth\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider identity linked to a user (E22). One row per (provider, user).
 *
 * @property-read User $user
 */
#[UseFactory(SocialAccountFactory::class)]
final class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'name',
        'avatar_url',
        'last_login_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
