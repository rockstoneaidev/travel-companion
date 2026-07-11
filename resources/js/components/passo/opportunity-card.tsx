import { cn } from '@/lib/utils';
import { PrimaryPill, QuietAction, TextAction } from './buttons';
import { GoNowBadge } from './go-now';
import { SectionLabel } from './section-label';

export interface OpportunityCardProps {
    title: string;
    /** The one- or two-sentence "why now" summary. */
    summary: string;
    /** Max 2 shown (DESIGN §3); extra entries are ignored. */
    facets?: string[];
    /** Practical meta row, e.g. "6 min walk · free". */
    meta: string;
    /** Present only on the (at most one) urgent card per feed. */
    urgency?: { remaining: number; note: string };
    onTakeMe?: () => void;
    onKeep?: () => void;
    className?: string;
}

/**
 * The core Passo object (DESIGN §3). Standard card: quiet, facet caps, text actions.
 * Urgent card: 1.5px ochre border, GO NOW badge, filled Take me pill — max one per feed.
 */
export function OpportunityCard({ title, summary, facets = [], urgency, meta, onTakeMe, onKeep, className }: OpportunityCardProps) {
    const urgent = urgency != null;

    return (
        <article
            className={cn(
                'rounded-card bg-card p-4',
                urgent ? 'border-urgent shadow-urgent border-[1.5px]' : 'border-border shadow-card border',
                className,
            )}
        >
            {urgent ? (
                <GoNowBadge remaining={urgency.remaining} note={urgency.note} className="mb-3" />
            ) : (
                facets.length > 0 && <SectionLabel className="mb-2">{facets.slice(0, 2).join(' · ')}</SectionLabel>
            )}

            <h3 className={cn('text-ink font-serif font-medium', urgent ? 'text-title-hero' : 'text-title')}>{title}</h3>
            <p className="text-body-card text-body mt-1.5">{summary}</p>

            <div className={cn('mt-3 flex items-center justify-between gap-3 pt-3', !urgent && 'border-border-soft border-t')}>
                <span className="text-meta-row text-meta font-medium">{meta}</span>
                <span className="flex items-center gap-4">
                    {urgent ? (
                        <PrimaryPill onClick={onTakeMe}>Take me</PrimaryPill>
                    ) : (
                        <>
                            <TextAction onClick={onTakeMe}>Take me</TextAction>
                            <QuietAction onClick={onKeep}>Keep</QuietAction>
                        </>
                    )}
                </span>
            </div>
        </article>
    );
}
