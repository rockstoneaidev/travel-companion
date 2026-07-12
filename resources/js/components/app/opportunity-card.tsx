import { cn } from '@/lib/utils';
import { PrimaryPill, QuietAction, TextAction } from './buttons';
import { GoNowBadge } from './go-now';
import { SectionLabel } from './section-label';

export interface OpportunityCardProps {
    /** The photo, with its attribution. Null renders the paper-stripe fallback. */
    image?: { url: string; attribution: string | null; license: string | null } | null;
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
 * The core product object (DESIGN §3). Standard card: quiet, facet caps, text actions.
 * Urgent card: 1.5px ochre border, GO NOW badge, filled Take me pill — max one per feed.
 */
export function OpportunityCard({ image, title, summary, facets = [], urgency, meta, onTakeMe, onKeep, className }: OpportunityCardProps) {
    const urgent = urgency != null;

    return (
        <article
            className={cn(
                'rounded-card bg-card flex flex-col overflow-hidden',
                urgent ? 'border-urgent shadow-urgent border-[1.5px]' : 'border-border shadow-card border',
                className,
            )}
        >
            {/*
             * The photo (DESIGN §3). The paper-stripe placeholder is the DESIGNED
             * fallback, not an error state — most of the long tail has no photo, and a
             * card without one has to look intentional rather than broken.
             *
             * Attribution is rendered ON the image, because a per-image credit that
             * lives in a page footer is a credit somebody eventually forgets to render.
             */}
            {image != null ? (
                <div className="relative">
                    <img src={image.url} alt="" loading="lazy" className="h-40 w-full object-cover sm:h-44" />
                    {image.attribution !== null && (
                        <span className="bg-ink/55 text-card absolute right-0 bottom-0 max-w-full truncate rounded-tl px-1.5 py-0.5 text-[10px]">
                            {image.attribution}
                        </span>
                    )}
                </div>
            ) : (
                <div className="paper-stripe h-24 w-full" />
            )}

            <div className="flex flex-1 flex-col p-4">
                {urgent ? (
                    <GoNowBadge remaining={urgency.remaining} note={urgency.note} className="mb-3" />
                ) : (
                    facets.length > 0 && <SectionLabel className="mb-2">{facets.slice(0, 2).join(' · ')}</SectionLabel>
                )}

                <h3 className={cn('text-ink font-serif font-medium', urgent ? 'text-title-hero' : 'text-title')}>{title}</h3>
                <p className="text-body-card text-body mt-1.5 flex-1">{summary}</p>

                <div className={cn('mt-3 flex items-center justify-between gap-3 pt-3', !urgent && 'border-border-soft border-t')}>
                    <span className="text-meta-row text-meta font-medium">{meta}</span>
                    <span className="flex items-center gap-4">
                        {/* Card actions must not bubble into a card-level tap-through (S1). */}
                        {urgent ? (
                            <PrimaryPill onClick={(e) => (e.stopPropagation(), onTakeMe?.())}>Take me</PrimaryPill>
                        ) : (
                            <>
                                <TextAction onClick={(e) => (e.stopPropagation(), onTakeMe?.())}>Take me</TextAction>
                                <QuietAction onClick={(e) => (e.stopPropagation(), onKeep?.())}>Keep</QuietAction>
                            </>
                        )}
                    </span>
                </div>
            </div>
        </article>
    );
}
