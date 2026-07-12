<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reduces source prose to something a traveller can actually be told.
 *
 * Our evidence comes from Wikipedia, Wikivoyage, tourism-board CMSes and
 * ministry exports, and all of them leak their own markup. A curated claim that
 * reached approval read:
 *
 *     "...this lake, which allows [[water sports."
 *
 * That is a wiki link with its closing brackets lost to a truncation, and it was
 * approved and live. It would have been read aloud to somebody standing next to
 * the lake.
 *
 * So markup is stripped at the BOUNDARY — where evidence enters and where a claim
 * is written — not politely cleaned up later. Two reasons it belongs here rather
 * than in review:
 *
 *   1. A curator scanning eighty drafts should be judging whether a claim is
 *      TRUE, not proofreading someone else's CMS.
 *   2. The model sees the evidence. Feed it `[[water sports]]` and it will happily
 *      copy the brackets through into prose, and now the markup is laundered.
 */
final class PlainText
{
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // [[Target|shown text]] → "shown text"; [[Target]] → "Target".
        $text = preg_replace('/\[\[(?:[^\]|]*\|)?([^\]|]*)\]\]/u', '$1', $text) ?? $text;

        // A truncated link — "[[water sports" — is exactly the bug above. The
        // closing brackets were cut off by an excerpt limit, so the pattern above
        // cannot match. Drop any surviving bracket runs.
        $text = preg_replace('/\[\[|\]\]/u', '', $text) ?? $text;

        // {{templates}} and their contents — never prose, always machinery.
        $text = preg_replace('/\{\{[^}]*\}\}/u', '', $text) ?? $text;
        $text = preg_replace('/\{\{|\}\}/u', '', $text) ?? $text;

        // '''bold''' / ''italic'' wiki emphasis.
        $text = preg_replace("/'{2,}/u", '', $text) ?? $text;

        // HTML from CMS-authored tourism copy.
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Reference markers: [1], [citation needed].
        $text = preg_replace('/\[\s*(?:\d+|citation needed|source)\s*\]/iu', '', $text) ?? $text;

        // Collapse the whitespace all of the above leaves behind, and tidy the
        // orphaned punctuation a stripped link can strand (" ," / " .").
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /** True if the text still carries markup a traveller should never be shown. */
    public static function hasMarkup(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        return preg_match('/\[\[|\]\]|\{\{|\}\}|<[a-z\/]|&[a-z]+;|&#\d/iu', $text) === 1;
    }
}
