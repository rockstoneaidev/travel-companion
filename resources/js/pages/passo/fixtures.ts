import { type EvidenceItem, type OpportunityCardData } from '@/types/passo';

/**
 * Fixtures for the kit gallery.
 *
 * The copy here is not filler — it is the voice under test (DESIGN §6): first person,
 * present tense, concrete specifics over adjectives, honest about time, no exclamation
 * marks, no "hidden gem". A component that looks right with lorem ipsum and wrong with
 * the real voice has not been reviewed.
 */

export const URGENT_OPPORTUNITY: OpportunityCardData = {
    id: 'sao-roque',
    title: 'The gilded chapel at São Roque',
    summary: 'The west windows only reach the gold leaf for the last hour before closing. It is six minutes from where you are standing.',
    facets: ['history', 'architecture'],
    meta: [{ label: '6 min walk' }, { label: 'free' }],
    urgency: {
        remaining_minutes: 40,
        total_minutes: 55,
        note: '~40 min of light left',
    },
};

export const STANDARD_OPPORTUNITIES: OpportunityCardData[] = [
    {
        id: 'padaria-sao-bento',
        title: 'Last bake at Padaria São Bento',
        summary: 'They pull the final tray at half past six and usually sell out inside twenty minutes. Two streets off your way back.',
        facets: ['food_drink', 'local_life'],
        meta: [{ label: '4 min walk' }, { label: '€' }],
    },
    {
        id: 'santa-luzia',
        title: 'Santa Luzia, after the crowds',
        summary: 'The tour buses empty out before seven. The terrace is worth more when you can hear the river.',
        facets: ['scenic', 'romantic'],
        meta: [{ label: '11 min walk' }, { label: 'free' }],
    },
];

export const EVIDENCE: EvidenceItem[] = [
    { claim: 'Open until 19:00', source: 'parish site', checked_at_label: 'checked 16:50' },
    { claim: 'Sunset 19:44, clear', source: 'met.no', checked_at_label: 'checked 17:05' },
    { claim: 'No entry fee to the chapel', source: 'OpenStreetMap', checked_at_label: 'checked yesterday' },
];

export const WHY_YOU =
    'You keep choosing the quiet interior over the famous facade, and you have never once wanted the queue. This is that, with better light.';

export const LEDE = 'One thing worth going for now. Two I would keep until tomorrow.';

export const CONTEXT = { city: 'Lisbon', time: '17:12' };
