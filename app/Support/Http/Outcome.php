<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * The three answers a harvest request can give — and the reason this file exists.
 *
 * Two of these used to be one. `FetchWikipediaExtracts` returned an empty array both
 * when Wikipedia said "this article does not exist" and when Wikipedia said "you are
 * asking too fast" (HTTP 429). The caller could not tell them apart, so it wrote the
 * same thing to the database in both cases: nothing.
 *
 * The result was a region that looked like a place nobody had ever written about.
 * Stockholm carried 4,326 Wikipedia links and 20 stored articles, the curation
 * selector mandates evidence, and so the home region was structurally incapable of
 * producing a candidate. Nobody noticed for weeks, because a silent lie about the
 * world reads exactly like the truth.
 *
 * ABSENT is a fact. UNKNOWN is the absence of a fact. Code that conflates them will
 * write fiction into the world model and be entirely confident about it.
 */
enum Outcome
{
    /** We asked, and got an answer. */
    case Ok;

    /** We asked, and the answer is "there is nothing here". A fact about the world. */
    case Absent;

    /**
     * We never got an answer — throttled, timed out, refused, or the server fell over.
     *
     * NEVER persist this as absence. Leave the row a candidate and ask again later.
     */
    case Unknown;
}
