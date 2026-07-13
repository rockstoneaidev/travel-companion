<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * The outcome of a harvest request, with the answer if there is one.
 *
 * The point of the type is that you cannot read the body without first having said
 * which of the three worlds you are in (see {@see Outcome}). `json()` on an UNKNOWN
 * result returns null rather than an empty array, so a caller that forgets to check
 * gets a null it has to think about instead of an emptiness it will happily store.
 */
final readonly class HarvestResult
{
    public function __construct(
        public Outcome $outcome,
        public ?Response $response,
        public int $attempts,
        /** Why we gave up, for the log. Null when we did not. */
        public ?string $reason = null,
        /**
         * When the server says to come back, in seconds — `Retry-After`, or the
         * `x-ratelimit-reset` that DATAtourisme uses instead.
         *
         * Present only when we were rate-limited for longer than we were willing to
         * wait. It is the difference between "try again in a moment" and "the bucket is
         * empty for the next fifty minutes", and a caller that cannot tell those apart
         * will keep asking while being told to stop.
         */
        public ?int $retryAfterSeconds = null,
    ) {}

    public function ok(): bool
    {
        return $this->outcome === Outcome::Ok;
    }

    /** The server told us there is nothing here. Safe to record as a fact. */
    public function absent(): bool
    {
        return $this->outcome === Outcome::Absent;
    }

    /** We never got an answer. NEVER record this as absence — ask again later. */
    public function unknown(): bool
    {
        return $this->outcome === Outcome::Unknown;
    }

    public function json(?string $key = null): mixed
    {
        if (! $this->ok()) {
            return null;
        }

        return $this->response?->json($key);
    }

    /**
     * For callers that would rather die than continue on a maybe.
     *
     * The ingest adapters are exactly that: a box that fails is re-run, and a box
     * that silently returns half a city is a world model nobody can trust. They
     * used to get this from `$response->throw()`; they still get it, but now with
     * the backoff having already been tried.
     */
    public function throwIfUnknown(string $context): self
    {
        if ($this->unknown()) {
            throw new RuntimeException(sprintf(
                '%s: no answer after %d attempts (%s).',
                $context, $this->attempts, $this->reason ?? 'unknown',
            ));
        }

        return $this;
    }
}
