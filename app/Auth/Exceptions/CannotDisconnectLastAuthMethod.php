<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

/**
 * Disconnecting this would leave the account with no way to log in: no password
 * set, and no other provider linked. Locking a user out of their own account is
 * not a setting we let them toggle.
 */
final class CannotDisconnectLastAuthMethod extends SocialAuthException
{
    public function __construct()
    {
        parent::__construct('Refusing to remove the last remaining authentication method.');
    }

    public function userMessage(): string
    {
        return 'This is the only way you can sign in. Set a password first, then disconnect.';
    }
}
