import { type ClassValue, clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// tailwind-merge cannot know that the Passo type-scale utilities (text-title,
// text-facet, …) are font sizes, not colors — without this it puts them in the
// text-color group and silently drops the size whenever a color is present in
// the same class list. Keep in sync with the --text-* tokens in app.css.
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            'font-size': [
                {
                    text: [
                        'wordmark',
                        'headline',
                        'title-hero',
                        'title',
                        'title-detail',
                        'lede',
                        'body-card',
                        'body-detail',
                        'meta-row',
                        'facet',
                        'gonow',
                    ],
                },
            ],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
