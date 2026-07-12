import { cn } from '@/lib/utils';

export interface ThumbImage {
    url: string;
    attribution: string | null;
    license: string | null;
}

/**
 * The small square photo on a list row — KEPT, JOURNAL, the digest.
 *
 * One component, so the three list screens cannot drift apart, and so the
 * paper-stripe fallback is applied in exactly one place. That fallback is the
 * DESIGNED state (DESIGN §3), not an error: most of the long tail has no photo, and
 * a row without one has to look intentional rather than broken.
 */
export function Thumb({ image, className }: { image: ThumbImage | null; className?: string }) {
    if (image === null) {
        return <div className={cn('paper-stripe size-14 shrink-0 rounded-lg', className)} />;
    }

    return (
        <img
            src={image.url}
            alt=""
            loading="lazy"
            title={image.attribution ?? undefined}
            className={cn('size-14 shrink-0 rounded-lg object-cover', className)}
        />
    );
}
