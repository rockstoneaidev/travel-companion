import { cn } from '@/lib/utils';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { type ButtonHTMLAttributes, forwardRef } from 'react';

/**
 * The Passo action vocabulary (DESIGN §1.1 rule 4 — this list is closed):
 *
 *   primary   — "Take me", "Start exploring": filled terracotta pill.
 *   secondary — "Keep" on detail: outlined pill.
 *   text      — "Take me" on non-hero cards: underlined ink.
 *   quiet     — "Keep", "Not for me": readable `meta`, never `muted` (§5).
 *
 * Never "Book", "Explore", "Discover", "View details", "Learn more".
 *
 * Colour means one thing — go now (§1.1 rule 1). Terracotta is the *primary action*
 * fill and nothing else; selection states are ink.
 */
const passoButton = cva(
    'inline-flex items-center justify-center gap-1.5 rounded-pill whitespace-nowrap transition-colors duration-200 ease-passo disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                primary: 'bg-terracotta text-on-terracotta hover:bg-terracotta/90 px-4 py-2 text-btn font-bold',
                secondary: 'border-border-strong text-ink hover:bg-border-soft border px-4 py-2 text-btn font-semibold',
                text: 'text-ink text-btn-sm font-semibold underline decoration-1 [text-underline-offset:3px] hover:decoration-2',
                quiet: 'text-meta hover:text-ink text-btn-sm font-medium',
            },
            /** Cards pack text actions tightly; `comfortable` meets the 44px touch target. */
            density: {
                comfortable: 'tap-target',
                compact: 'min-h-[2rem]',
            },
        },
        defaultVariants: {
            variant: 'primary',
            density: 'comfortable',
        },
    },
);

export interface PassoButtonProps extends ButtonHTMLAttributes<HTMLButtonElement>, VariantProps<typeof passoButton> {
    /** Render as the child element (e.g. an Inertia `<Link>`) while keeping the styling. */
    asChild?: boolean;
}

export const PassoButton = forwardRef<HTMLButtonElement, PassoButtonProps>(function PassoButton(
    { className, variant, density, asChild = false, ...props },
    ref,
) {
    const Comp = asChild ? Slot : 'button';

    return <Comp ref={ref} className={cn(passoButton({ variant, density }), className)} {...props} />;
});

/**
 * Calibration choice pill (DESIGN §3 / SCREENS S9).
 *
 * Selected is **filled ink**, not terracotta — selection is not urgency (§1.1 rule 1).
 */
export interface ChoicePillProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    selected?: boolean;
}

export const ChoicePill = forwardRef<HTMLButtonElement, ChoicePillProps>(function ChoicePill(
    { className, selected = false, type = 'button', ...props },
    ref,
) {
    return (
        <button
            ref={ref}
            type={type}
            aria-pressed={selected}
            className={cn(
                'rounded-pill tap-target text-btn ease-passo inline-flex items-center justify-center border px-4 py-2 font-medium transition-colors duration-200',
                selected
                    ? 'bg-ink text-on-terracotta border-ink'
                    : 'border-border-strong text-meta hover:text-ink hover:border-ink/40 bg-transparent',
                className,
            )}
            {...props}
        />
    );
});
