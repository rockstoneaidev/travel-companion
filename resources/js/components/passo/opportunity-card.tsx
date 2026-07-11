import { PassoButton } from '@/components/passo/button';
import { FacetLabels, MetaRow } from '@/components/passo/meta';
import { UrgencyHeader } from '@/components/passo/urgency';
import { cn } from '@/lib/utils';
import { type OpportunityCardData } from '@/types/passo';

export interface OpportunityCardProps {
    opportunity: OpportunityCardData;
    /**
     * The GO NOW variant. A feed shows **at most one** (DESIGN §1.1 rule 2) — the server
     * guarantees this (SCREENS S1); the component does not police it.
     * Requires `opportunity.urgency`; without a window there is nothing to be urgent about.
     */
    urgent?: boolean;
    onTakeMe?: () => void;
    onKeep?: () => void;
    onOpen?: () => void;
    className?: string;
    /** Feed position — drives the ~40ms settle-in stagger (DESIGN §2.5). */
    index?: number;
}

/**
 * The core object. An *opportunity*, not a place.
 *
 * Standard:  facet caps → title → "why now" summary → hairline → meta + text actions.
 * Urgent:    ring + GO NOW label on top, 1.5px ochre border, urgent shadow, and the
 *            *Take me* action promoted to a filled terracotta pill.
 */
export function OpportunityCard({ opportunity, urgent = false, onTakeMe, onKeep, onOpen, className, index = 0 }: OpportunityCardProps) {
    // The urgent styling is meaningless without a window to draw (DESIGN §1.1 rule 3).
    const isUrgent = urgent && opportunity.urgency !== undefined;

    return (
        <article
            className={cn(
                'bg-card rounded-card animate-settle-in flex flex-col gap-2.5 p-4',
                isUrgent ? 'border-urgent shadow-urgent border-[1.5px]' : 'border-border shadow-card border',
                className,
            )}
            style={{ animationDelay: `${index * 40}ms` }}
            aria-labelledby={`opportunity-${opportunity.id}-title`}
        >
            {isUrgent && opportunity.urgency ? <UrgencyHeader window={opportunity.urgency} /> : <FacetLabels facets={opportunity.facets} />}

            <div className="flex flex-col gap-1.5">
                <h3
                    id={`opportunity-${opportunity.id}-title`}
                    className={cn('text-ink font-serif font-medium', isUrgent ? 'text-title-lg' : 'text-title')}
                >
                    {onOpen ? (
                        <button type="button" onClick={onOpen} className="rounded-xs text-left">
                            {opportunity.title}
                        </button>
                    ) : (
                        opportunity.title
                    )}
                </h3>
                <p className="text-body text-copy">{opportunity.summary}</p>
            </div>

            <hr className="border-border-soft border-t" />

            <div className="flex items-center justify-between gap-3">
                <MetaRow facts={opportunity.meta} />

                <div className="flex shrink-0 items-center gap-3">
                    {isUrgent ? (
                        <PassoButton variant="primary" onClick={onTakeMe}>
                            Take me
                        </PassoButton>
                    ) : (
                        <>
                            <PassoButton variant="text" density="compact" onClick={onTakeMe}>
                                Take me
                            </PassoButton>
                            <PassoButton variant="quiet" density="compact" onClick={onKeep}>
                                Keep
                            </PassoButton>
                        </>
                    )}
                </div>
            </div>
        </article>
    );
}

/**
 * The still-computing card (SCREENS S1, PRD §10 latency budget): cached results land
 * instantly and this holds the space below them. Paper stripe, no spinner, no shimmer.
 */
export function OpportunityCardPlaceholder({ className }: { className?: string }) {
    return (
        <div className={cn('bg-card rounded-card border-border shadow-card flex flex-col gap-3 border p-4', className)}>
            <div className="paper-stripe h-2.5 w-24 rounded-xs" />
            <div className="paper-stripe h-4 w-3/4 rounded-xs" />
            <div className="paper-stripe h-2.5 w-full rounded-xs" />
            <div className="paper-stripe h-2.5 w-2/3 rounded-xs" />
        </div>
    );
}
