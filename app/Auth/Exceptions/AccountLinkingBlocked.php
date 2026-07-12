<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

/**
 * A local account already holds this email, but nothing has ever proved that
 * account's owner reads that inbox — and registration is open, so anyone could
 * have created it.
 *
 * Linking anyway is the classic pre-registration takeover: an attacker signs up
 * with victim@example.com and a password only they know; the victim later clicks
 * "Continue with Google", gets merged into that account, and the attacker walks
 * in behind them with the password they set.
 *
 * The user is not stuck: log in with the password (or reset it, which proves the
 * inbox), then connect Google from settings.
 *
 * See ResolveSocialIdentity::mayLinkByEmail() for when this fires. Once email
 * verification ships, verified accounts stop hitting this entirely.
 */
final class AccountLinkingBlocked extends SocialAuthException
{
    public function __construct(string $email)
    {
        parent::__construct("Refusing to link a social identity to unverified account {$email} while registration is open.");
    }

    public function userMessage(): string
    {
        return 'An account with this email address already exists. Log in with your password first, then connect Google from your settings.';
    }
}
