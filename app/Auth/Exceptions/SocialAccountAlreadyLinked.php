<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

use App\Auth\Enums\SocialProvider;

/**
 * Either this provider identity is already connected to a different user, or the
 * user is trying to connect a second identity from a provider they have already
 * connected. Both are the `social_accounts` unique indexes, caught in the domain
 * so the user gets a sentence instead of a 500.
 */
final class SocialAccountAlreadyLinked extends SocialAuthException
{
    private function __construct(private readonly string $userMessage, string $logMessage)
    {
        parent::__construct($logMessage);
    }

    public static function toAnotherUser(SocialProvider $provider): self
    {
        return new self(
            "That {$provider->label()} account is already connected to a different account.",
            "Provider identity is already linked to another user ({$provider->value}).",
        );
    }

    public static function forThisUser(SocialProvider $provider): self
    {
        return new self(
            "A different {$provider->label()} account is already connected. Disconnect it first.",
            "User already has a different {$provider->value} identity linked.",
        );
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }
}
