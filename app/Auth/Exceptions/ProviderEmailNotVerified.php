<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

use App\Auth\Enums\SocialProvider;

/**
 * The provider handed us an email it has not itself verified.
 *
 * Everything downstream — matching an existing account by email, creating one
 * with `email_verified_at` set — trusts that Google proved ownership. If it
 * didn't, the identity is worthless and we stop here.
 */
final class ProviderEmailNotVerified extends SocialAuthException
{
    public function __construct(public readonly SocialProvider $provider, string $email)
    {
        parent::__construct("{$provider->label()} reports {$email} as unverified.");
    }

    public function userMessage(): string
    {
        return "Your {$this->provider->label()} account's email address isn't verified. Verify it with {$this->provider->label()}, then try again.";
    }
}
