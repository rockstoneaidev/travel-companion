import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The market-facing product name, from `APP_NAME` via the shared Inertia prop.
 *
 * "Passo" is the permanent *internal* codename of the design system (namespaces,
 * docs, tokens) — it is never the wordmark. The market name is provisional, so it
 * lives in config and is read here: renaming the product must stay a config change,
 * not a refactor (DESIGN.md §1, SCREENS.md build note 3).
 */
export function useAppName(): string {
    return usePage<SharedData>().props.name;
}
