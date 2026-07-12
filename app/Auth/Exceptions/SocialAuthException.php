<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

use RuntimeException;

/**
 * Base for every way a social login can be refused.
 *
 * These carry a user-facing message because the delivery layer's only sensible
 * response is to bounce back to the login screen and say why — there is no form
 * field to attach an error to, and no domain-to-HTTP mapping worth writing for
 * three cases. `userMessage()` is what the user reads; the exception message is
 * what the log gets.
 */
abstract class SocialAuthException extends RuntimeException
{
    abstract public function userMessage(): string;
}
