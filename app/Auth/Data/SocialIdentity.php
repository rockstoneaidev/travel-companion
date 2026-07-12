<?php

declare(strict_types=1);

namespace App\Auth\Data;

use App\Auth\Enums\SocialProvider;
use Illuminate\Support\Str;

/**
 * A provider-asserted identity, already authenticated by the provider.
 *
 * This DTO is the seam that keeps Phase 2 additive (CLAUDE.md, API-first): the
 * web redirect callback builds one from a Socialite user, and the future mobile
 * client will build the same thing from a verified Google ID token. Everything
 * downstream — linking, allowlisting, account creation — consumes only this, and
 * so does not know or care which transport produced it.
 *
 * `emailVerified` is the provider's own claim (Google's `email_verified`), not
 * ours. ResolveSocialIdentity refuses an identity where it is false: an
 * unverified provider email would let anyone log in as anyone by setting that
 * address on a throwaway account.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public SocialProvider $provider,
        public string $providerUserId,
        public string $email,
        public bool $emailVerified,
        public ?string $name = null,
        public ?string $avatarUrl = null,
    ) {}

    /** Emails are stored lowercase; the allowlist and the users unique index both compare exactly. */
    public function normalizedEmail(): string
    {
        return Str::lower(trim($this->email));
    }

    /** Falls back to the local part of the email — `name` is optional on some providers. */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== ''
            ? $name
            : Str::before($this->normalizedEmail(), '@');
    }
}
