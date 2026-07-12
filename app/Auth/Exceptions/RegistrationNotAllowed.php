<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

/**
 * A Google account with no matching user, whose email is not on the pre-launch
 * allowlist. Signing in with Google is a registration path, and the allowlist
 * governs every registration path.
 */
final class RegistrationNotAllowed extends SocialAuthException
{
    public function __construct(string $email)
    {
        parent::__construct("{$email} is not on the registration allowlist.");
    }

    public function userMessage(): string
    {
        // Deliberately the same wording as the email/password form's
        // `email.in` message: whether an address is invited is not a secret
        // worth leaking a distinction over.
        return 'Registration is currently limited to invited email addresses.';
    }
}
