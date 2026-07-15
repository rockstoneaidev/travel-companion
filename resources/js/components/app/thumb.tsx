import { cn } from '@/lib/utils';
import { useState } from 'react';

export interface ThumbImage {
    url: string;
    attribution: string | null;
    license: string | null;
}

/**
 * The small square photo on a list row — KEPT, JOURNAL, the digest, browse.
 *
 * One component, so the list screens cannot drift apart, and so the paper-stripe fallback is
 * applied in exactly one place. That fallback is the DESIGNED state (DESIGN §3), not an
 * error: most of the long tail has no photo, and a row without one has to look intentional
 * rather than broken.
 *
 * ## Why the onError fallback is load-bearing, not defensive nicety
 *
 * A stored URL that resolves 200 from our server can still fail to load in the browser — the
 * founder hit exactly this, blue broken-image boxes on Pontonjärparken and Bergsgruvan whose
 * Commons URLs were perfectly valid. Wikimedia generates those `thumb/` sizes on demand and
 * intermittently rate-limits or times out the first request; the browser then shows its own
 * broken-image glyph and stays stuck on it.
 *
 * Without a fallback, "the image URL is fine but this one fetch failed" renders as breakage.
 * With it, a failed load is indistinguishable from having no photo at all — which is the
 * honest outcome: we could not show a picture, so we show the designed absence-of-picture,
 * whatever the reason. This also covers a genuine 404 and a hotlink block for free.
 */
export function Thumb({ image, className }: { image: ThumbImage | null; className?: string }) {
    const [failed, setFailed] = useState(false);

    if (image === null || failed) {
        return <div className={cn('paper-stripe size-14 shrink-0 rounded-lg', className)} />;
    }

    return (
        <img
            src={image.url}
            alt=""
            loading="lazy"
            title={image.attribution ?? undefined}
            onError={() => setFailed(true)}
            className={cn('size-14 shrink-0 rounded-lg object-cover', className)}
        />
    );
}
