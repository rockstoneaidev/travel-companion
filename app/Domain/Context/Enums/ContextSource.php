<?php

declare(strict_types=1);

namespace App\Domain\Context\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a position came from: a real device, or an operator emulating one (ADMIN §6).
 *
 * This is the enum that keeps our own testing out of our own numbers. ADMIN §14 calls
 * that "a CLAUDE.md-grade invariant" and it is not hyperbole: an operator walking a
 * synthetic path through Stockholm generates feedback, spend and traces exactly like a
 * user does, because it goes through exactly the same pipeline — which is the whole
 * point of the emulator, and precisely why it is dangerous. Without this flag, the
 * console we built to watch the metrics would be the thing corrupting them.
 *
 * **The client never asserts this.** A context event inherits its source from its
 * SESSION, so no request payload can claim `emulated` and no real phone can be
 * mislabelled as one. Provenance is a property of the session, not of a POST body —
 * if it were the latter, "is this real?" would be a question the caller answers about
 * itself.
 */
enum ContextSource: string
{
    use HasOptions;

    /** A real phone, held by a real person, who is really there. */
    case Device = 'device';

    /** An operator driving a pin around a map (ADMIN §6). Never learned from. */
    case Emulated = 'emulated';

    public function label(): string
    {
        return match ($this) {
            self::Device => 'Device',
            self::Emulated => 'Emulated',
        };
    }

    /**
     * May what happened under this context teach the taste profile, count toward
     * product cost metrics, or be recorded as a gold trace?
     *
     * One method, asked at every seam, so that "is this real?" has exactly one answer
     * in the codebase rather than three subtly different ones.
     */
    public function isReal(): bool
    {
        return $this === self::Device;
    }
}
