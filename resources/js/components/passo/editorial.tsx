import { cn } from '@/lib/utils';
import { type EvidenceItem } from '@/types/passo';
import { type ReactNode } from 'react';

/**
 * The italic sentence under the header summarising the feed:
 * "One thing worth going for now. Two keep until tomorrow."
 *
 * Written by the backend voice layer — never assembled client-side. The LLM is not a
 * source of facts (constraint 3); this is the *phrasing* of facts the server already has.
 */
export function EditorialLede({ children, className }: { children: ReactNode; className?: string }) {
    return <p className={cn('text-body text-lede font-serif italic', className)}>{children}</p>;
}

/** The small caps section label used by WHY YOU / EVIDENCE. */
function SectionLabel({ children }: { children: ReactNode }) {
    return <p className="text-meta text-facet tracking-facet font-medium uppercase">{children}</p>;
}

/**
 * WHY YOU — the personal-taste explanation, in the companion's voice.
 * Newsreader italic; comes from the explanation endpoint (SCREENS S4).
 */
export function WhyYou({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <section className={cn('flex flex-col gap-2', className)}>
            <SectionLabel>Why you</SectionLabel>
            <p className="text-body text-copy-lg font-serif italic">{children}</p>
        </section>
    );
}

/**
 * EVIDENCE — what the recommendation is actually standing on, with source and check time.
 *
 * Source transparency is a product requirement (PRD §16), not a debug affordance: the
 * companion earns trust by showing its work, and the LLM never invents a row here.
 */
export function EvidenceList({ items, className }: { items: EvidenceItem[]; className?: string }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section className={cn('flex flex-col gap-2', className)}>
            <SectionLabel>Evidence</SectionLabel>
            <ul className="flex flex-col gap-1.5">
                {items.map((item, index) => (
                    <li key={index} className="text-micro flex flex-wrap gap-x-1.5">
                        <span className="text-body font-medium">{item.claim}</span>
                        <span className="text-meta">
                            — {item.source}, {item.checked_at_label}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Calibration progress (SCREENS S9): equal flex segments, 3px tall.
 * Done and current are terracotta; the rest are border.
 */
export function ProgressSegments({ total, current, className }: { total: number; current: number; className?: string }) {
    return (
        <div
            className={cn('flex w-full gap-1', className)}
            role="progressbar"
            aria-valuemin={1}
            aria-valuemax={total}
            aria-valuenow={current}
            aria-label={`Step ${current} of ${total}`}
        >
            {Array.from({ length: total }, (_, index) => (
                <span
                    key={index}
                    className={cn('h-[3px] flex-1 rounded-xs transition-colors duration-200', index < current ? 'bg-terracotta' : 'bg-border')}
                />
            ))}
        </div>
    );
}
