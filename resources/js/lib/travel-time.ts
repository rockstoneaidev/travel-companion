import { type TravelMode } from '@/types/enums';

/**
 * How to SAY a travel time — in one place, because it was being said in four and every
 * one of them said "walk".
 *
 * The number has always been mode-aware: the Stage-A estimator and the Stage-B route
 * both take the session's travel mode. Only the words were wrong. So a session set to
 * Drive produced a perfectly correct 64-minute drive time and the card rendered it as
 * "64 minutes away on foot" — for a place 58 km up the E4.
 *
 * That is worse than a cosmetic slip. "On foot" is a claim about feasibility, and the
 * whole product rests on the reachability gate being believable.
 */

/** The meta line under a card: "12 min walk", "64 min drive". */
export function travelMeta(minutes: number | null, mode: TravelMode): string {
    const value = minutes === null ? '–' : Math.round(minutes);

    return `${value} min ${noun(mode)}`;
}

/** The fallback summary when a card has no generated line yet. */
export function travelSummary(minutes: number | null, mode: TravelMode): string {
    if (minutes === null) return `A short ${noun(mode)} away.`;

    return `${Math.round(minutes)} minutes away ${preposition(mode)}.`;
}

function noun(mode: TravelMode): string {
    return { walk: 'walk', bike: 'ride', drive: 'drive' }[mode] ?? 'walk';
}

function preposition(mode: TravelMode): string {
    return { walk: 'on foot', bike: 'by bike', drive: 'by car' }[mode] ?? 'on foot';
}
