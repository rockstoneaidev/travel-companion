import { cn } from '@/lib/utils';

interface EmptyFeedProps extends React.ComponentProps<'div'> {
    /** e.g. "Nothing worth interrupting you for." */
    headline: string;
    /** Warm body copy — what the companion is watching. */
    body: string;
    /** Caps footer, e.g. "Next likely moment · around 17:00". Omit when the backend has none. */
    nextMoment?: string;
}

/**
 * Silence as a first-class screen (DESIGN §3, SCREENS S5): confident, designed,
 * never an apologetic "no results" state.
 */
export function EmptyFeed({ headline, body, nextMoment, className, ...props }: EmptyFeedProps) {
    return (
        <div className={cn('flex flex-col items-center px-6 py-16 text-center', className)} {...props}>
            <div className="border-border-strong relative mb-8 size-14 rounded-full border border-dashed">
                <div className="bg-urgent absolute top-1/2 left-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full" />
            </div>
            <h2 className="text-headline text-ink font-serif font-medium italic">{headline}</h2>
            <p className="text-body-detail text-body mt-4 max-w-xs">{body}</p>
            {nextMoment && (
                <div className="border-border-soft mt-10 w-full max-w-xs border-t pt-4">
                    <span className="text-facet text-meta font-medium tracking-[.14em] uppercase">{nextMoment}</span>
                </div>
            )}
        </div>
    );
}
