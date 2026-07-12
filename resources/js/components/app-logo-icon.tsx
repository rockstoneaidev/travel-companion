import { SVGAttributes } from 'react';

/**
 * The brand mark: a ring with a terracotta dot at its centre — the "you are here"
 * figure, same as the PWA icons in public/icons (DESIGN §2.1). The ring takes
 * currentColor so it reads on paper and on dark surfaces; the dot stays terracotta.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" {...props}>
            <circle cx="16" cy="16" r="13" fill="none" stroke="currentColor" strokeWidth="2.5" />
            <circle cx="16" cy="16" r="4.25" fill="var(--color-terracotta)" stroke="none" />
        </svg>
    );
}
