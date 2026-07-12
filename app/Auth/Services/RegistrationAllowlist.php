<?php

declare(strict_types=1);

namespace App\Auth\Services;

use Illuminate\Support\Str;

/**
 * The pre-launch registration allowlist (CLAUDE.md), as an object rather than a
 * `Rule::in` buried in one controller.
 *
 * It has two callers now — the email/password form and the Google callback —
 * and a second registration path that forgets to consult it is how a closed
 * beta silently becomes open registration. So the check has exactly one
 * implementation and both paths call it.
 */
final readonly class RegistrationAllowlist
{
    /** @var list<string> */
    private array $emails;

    public function __construct(?array $emails = null)
    {
        /** @var list<string> $configured */
        $configured = $emails ?? config('auth.allowed_registration_emails', []);

        $this->emails = array_values($configured);
    }

    /** Empty allowlist = open registration (config/auth.php). */
    public function isOpen(): bool
    {
        return $this->emails === [];
    }

    /** Closed = invite-only. The state we are in pre-launch. */
    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }

    public function allows(string $email): bool
    {
        return $this->isOpen()
            || in_array(Str::lower(trim($email)), $this->emails, strict: true);
    }

    /** @return list<string> */
    public function emails(): array
    {
        return $this->emails;
    }
}
