import { type ClassValue, clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * tailwind-merge has to be taught the Passo scale.
 *
 * Tailwind's `text-*` namespace serves both font-size and colour. Out of the box,
 * tailwind-merge cannot tell `text-title-lg` (a size) from `text-ink` (a colour): it
 * files both under `text-color`, decides they conflict, and silently drops the first —
 * so `cn('text-ink', 'text-title-lg')` renders a card title with no colour at all, which
 * only becomes visible in dark mode. Declaring the custom size and colour names below
 * puts each class in the right group.
 *
 * Keep these lists in sync with the `@theme` block in `resources/css/app.css`.
 */
const PASSO_FONT_SIZES = [
    'wordmark',
    'headline',
    'title',
    'title-lg',
    'title-xl',
    'lede',
    'copy',
    'copy-lg',
    'note',
    'micro',
    'tab',
    'gonow',
    'facet',
    'stamp',
    'btn',
    'btn-sm',
];

const PASSO_COLORS = [
    'paper',
    'card',
    'ink',
    'body',
    'meta',
    'muted',
    'border',
    'border-soft',
    'border-strong',
    'terracotta',
    'on-terracotta',
    'urgent',
    'urgent-deep',
    'urgent-track',
    'olive',
    'map-bg',
    'map-road',
    'map-green',
];

const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': [{ text: PASSO_FONT_SIZES }],
            'text-color': [{ text: PASSO_COLORS }],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
