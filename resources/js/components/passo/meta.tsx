import { facetLabel } from '@/lib/passo';
import { cn } from '@/lib/utils';
import { type AppealFacet } from '@/types/enums';
import { type MetaFact } from '@/types/passo';

/**
 * Facet caps — "HISTORY · ARCHITECTURE". At most two (DESIGN §3); quiet `meta`,
 * never the accent (§1.1 rule 1: colour means "go now" and nothing else).
 */
export function FacetLabels({ facets, className }: { facets: AppealFacet[]; className?: string }) {
    const shown = facets.slice(0, 2);

    if (shown.length === 0) {
        return null;
    }

    return <p className={cn('text-meta text-facet tracking-facet font-medium uppercase', className)}>{shown.map(facetLabel).join(' · ')}</p>;
}

/**
 * The practical row — walk time, price band. Small, quiet, grouped: the title and the
 * "why now" sentence carry the card, not the metadata (DESIGN §1.1 rule 6).
 */
export function MetaRow({ facts, className }: { facts: MetaFact[]; className?: string }) {
    if (facts.length === 0) {
        return null;
    }

    return <p className={cn('text-meta text-micro font-medium', className)}>{facts.map((fact) => fact.label).join(' · ')}</p>;
}

/**
 * The context stamp: "LISBON · 17:12" — city from reverse geocode, local time.
 */
export function ContextStamp({ city, time, className }: { city: string; time: string; className?: string }) {
    return (
        <p className={cn('text-meta text-stamp tracking-stamp font-medium uppercase', className)}>
            {city} · {time}
        </p>
    );
}

/**
 * The paper-stripe placeholder (DESIGN §2.4) — the designed stand-in for a photo or a
 * still-computing card. Explicitly not a shimmer skeleton, and not an error state.
 */
export function PaperPlaceholder({ className }: { className?: string }) {
    return <div aria-hidden="true" className={cn('paper-stripe border-border rounded-block border', className)} />;
}

/**
 * The feed's closing line: "That's all for now." — scarcity is the product (PRD §12.1).
 */
export function EndNote({ children, className }: { children: React.ReactNode; className?: string }) {
    return <p className={cn('text-muted text-copy-lg text-center font-serif italic', className)}>{children}</p>;
}
