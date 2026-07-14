import { cn } from '@/lib/utils';

interface EmptyFeedProps extends React.ComponentProps<'div'> {
    /** e.g. "Nothing worth interrupting you for." */
    headline: string;
    /** Warm body copy — what the companion is watching. */
    body: string;
    /** Caps footer, e.g. "Next likely moment · around 17:00". Omit when the backend has none. */
    nextMoment?: string;
    /**
     * We are ingesting this area right now (E48).
     *
     * The dashed ring TURNS. It is the same ring the silent state already draws — nothing
     * new is introduced, it simply starts moving — and that is the design: silence and
     * working-on-it are the same screen, told apart by whether anything is happening.
     *
     * A spinner would say "the app is busy". A ring turning slowly around a fixed dot says
     * "I am looking around where you are", which is the true statement.
     */
    working?: boolean;
    /** Boxes done / total, while a region is being learned. */
    progress?: { done: number; total: number } | null;
}

/**
 * Silence as a first-class screen (DESIGN §3, SCREENS S5): confident, designed,
 * never an apologetic "no results" state.
 */
export function EmptyFeed({ headline, body, nextMoment, working = false, progress = null, className, ...props }: EmptyFeedProps) {
    const percent = progress !== null && progress.total > 0 ? Math.round((progress.done / progress.total) * 100) : null;

    return (
        <div className={cn('flex flex-col items-center px-6 py-16 text-center', className)} {...props}>
            <div
                className={cn(
                    'border-border-strong relative mb-8 size-14 rounded-full border border-dashed',
                    // Four seconds a turn — slow, on purpose. A fast spinner is an app that
                    // is anxious about how long it is taking. This one is patient, because
                    // the honest answer is that mapping a town takes a while and the user
                    // does not have to stand there for it. `motion-safe:` so it respects
                    // prefers-reduced-motion.
                    working && 'motion-safe:animate-[spin_4s_linear_infinite]',
                )}
            >
                <div className="bg-urgent absolute top-1/2 left-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full" />
            </div>

            <h2 className="text-headline text-ink font-serif font-medium italic">{headline}</h2>
            <p className="text-body-detail text-body mt-4 max-w-xs">{body}</p>

            {/*
             * A bar, not just a number. "6 of 55" is a fact; a bar that visibly moves is a
             * promise being kept — and the entire reason this state exists is so somebody
             * knows work is happening rather than wondering whether the app is broken.
             */}
            {percent !== null && progress !== null && (
                <div className="mt-8 w-full max-w-xs">
                    <div className="bg-border-soft h-1 w-full overflow-hidden rounded-full">
                        <div
                            className="bg-urgent h-full rounded-full transition-[width] duration-700 ease-out"
                            style={{ width: `${Math.max(2, percent)}%` }}
                        />
                    </div>
                    <span className="text-meta text-quiet mt-2 block tracking-[.14em] uppercase">
                        {progress.done} of {progress.total} areas mapped
                    </span>
                </div>
            )}

            {nextMoment && (
                <div className="border-border-soft mt-10 w-full max-w-xs border-t pt-4">
                    <span className="text-facet text-meta font-medium tracking-[.14em] uppercase">{nextMoment}</span>
                </div>
            )}
        </div>
    );
}
