import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface EmptyFeedProps {
    /** Italic serif headline. Default is the canonical line (DESIGN §6). */
    headline?: ReactNode;
    /** Warm body copy — what the companion is watching for. */
    children?: ReactNode;
    /** "AROUND 17:00", from the WATCHING items' windows. Omitted when the backend has none. */
    nextLikelyMoment?: string;
    className?: string;
}

/**
 * Silence is a first-class screen (DESIGN §1.1 rule 5).
 *
 * Confident, never apologetic: no "No results found", no illustration-of-sadness, no
 * retry button. The scarcity *is* the product (PRD §12.1) — this screen is the promise
 * being kept, so it is designed as carefully as the feed.
 *
 * Anatomy: 56px dashed circle with an 8px ochre dot, italic headline, body, then the
 * caps footer above a hairline.
 */
export function EmptyFeed({
    headline = 'Nothing worth interrupting you for.',
    children = "You're in a good spot — I'm checking the afternoon light and the market times. I'll be quiet until something can't wait.",
    nextLikelyMoment,
    className,
}: EmptyFeedProps) {
    return (
        <section className={cn('flex flex-col items-center gap-5 py-10 text-center', className)}>
            <div className="border-border-strong flex size-14 items-center justify-center rounded-full border border-dashed">
                <span aria-hidden="true" className="bg-urgent size-2 rounded-full" />
            </div>

            <h2 className="text-ink text-headline max-w-[18rem] font-serif font-medium italic">{headline}</h2>

            <p className="text-body text-copy max-w-[20rem]">{children}</p>

            {nextLikelyMoment ? (
                <div className="mt-2 flex w-full flex-col items-center gap-3">
                    <hr className="border-border-soft w-full border-t" />
                    <p className="text-meta text-facet tracking-facet font-medium uppercase">Next likely moment · {nextLikelyMoment}</p>
                </div>
            ) : null}
        </section>
    );
}
