import { type AppealFacet } from './enums';

/**
 * View-model shapes consumed by the `components/passo` kit.
 *
 * These are *presentation* types, not the API contract: the backend resources
 * (conventions/06) are the source of truth for the wire format, and the screens
 * (E9) map a resource onto these props. Keeping the kit on its own shapes is what
 * lets the demo gallery render every component without a database.
 */

/** A practical fact in the metadata row — small, quiet, grouped (DESIGN §1.1 rule 6). */
export interface MetaFact {
    /** e.g. "6 min walk", "free", "until ~19:00". Already humanised by the backend voice layer. */
    label: string;
}

/** The urgency window behind a GO NOW card. The ring fraction is remaining/total. */
export interface UrgencyWindow {
    /** Minutes of the window still available — drives the "~40 min of light left" note. */
    remaining_minutes: number;
    /** Total window length in minutes. `remaining / total` is the ring arc fraction. */
    total_minutes: number;
    /**
     * The honest, specific note beside the ring (DESIGN §6: "~40 min of light left").
     * Written by the backend; never assembled client-side from adjectives.
     */
    note: string;
}

/** One row of the EVIDENCE list — source transparency is a product requirement (PRD §16). */
export interface EvidenceItem {
    /** The claim, e.g. "Open until 19:00". */
    claim: string;
    /** Where it came from, e.g. "parish site". */
    source: string;
    /** When it was last verified, e.g. "checked 16:50". */
    checked_at_label: string;
}

/** The card object — an *opportunity*, never a place (PRD §1). */
export interface OpportunityCardData {
    id: string;
    title: string;
    /** The one- or two-sentence "why now". */
    summary: string;
    /** At most two are shown (DESIGN §3). */
    facets: AppealFacet[];
    /** Practical facts: walk time, price band. */
    meta: MetaFact[];
    /** Present only on the single urgent item a feed may contain (DESIGN §1.1 rule 2). */
    urgency?: UrgencyWindow;
}

/** The four tabs: NOW · MAP · KEPT · JOURNAL (DESIGN §3). */
export interface PassoTab {
    label: string;
    href: string;
}
