<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Auth\Models\SocialAccount;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    // HasApiTokens: /api/v1 is the contract the Phase 2 mobile client consumes
    // (CLAUDE.md — "API tokens via Sanctum"). The web app authenticates through
    // the session; the token guard is what makes the native client additive.
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Linked OAuth identities — Google today, Apple in Phase 2 (E22). */
    /** @return HasMany<SocialAccount, $this> */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * False for accounts created through Google that never set one. Such a user
     * can still log in (via the provider) and can still *set* a password from
     * settings — but `current_password` cannot be asked of them, and they must
     * not be allowed to unlink their last provider.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }
}
