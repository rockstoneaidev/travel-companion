import { type AppealFacet } from '@/types/enums';

/** Human labels for the appeal facets (docs/TAXONOMY.md). Rendered uppercase by the component. */
const FACET_LABELS: Record<AppealFacet, string> = {
    history: 'History',
    architecture: 'Architecture',
    nature: 'Nature',
    scenic: 'Scenic',
    food_drink: 'Food & Drink',
    art: 'Art',
    craft: 'Craft',
    spiritual: 'Spiritual',
    local_life: 'Local Life',
    family: 'Family',
    active: 'Active',
    offbeat: 'Offbeat',
    romantic: 'Romantic',
    educational: 'Educational',
};

export function facetLabel(facet: AppealFacet): string {
    return FACET_LABELS[facet];
}

/**
 * The ring arc fraction: time remaining / window length, clamped to [0, 1].
 * A window that has run out reads as an empty ring, never a negative arc.
 */
export function urgencyFraction(remainingMinutes: number, totalMinutes: number): number {
    if (totalMinutes <= 0) {
        return 0;
    }

    return Math.min(1, Math.max(0, remainingMinutes / totalMinutes));
}
