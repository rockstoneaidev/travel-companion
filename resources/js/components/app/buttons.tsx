import { cn } from '@/lib/utils';

/**
 * Action shapes (DESIGN §3 Buttons). The action *vocabulary* is fixed
 * (primary "Take me" · defer "Keep"/"Remind me" · reject "Not for me") — callers
 * pass those words, these components provide the shapes.
 */

/** Filled terracotta pill — the single primary action ("Take me", "Start exploring"). */
export function PrimaryPill({ className, ...props }: React.ComponentProps<'button'>) {
    return (
        <button
            className={cn(
                'bg-terracotta text-on-terracotta min-h-11 rounded-full px-5 py-2.5 text-sm font-bold',
                'transition-opacity duration-150 ease-out hover:opacity-90 disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

/** Outline pill — secondary action ("Keep" on the detail screen). */
export function SecondaryPill({ className, ...props }: React.ComponentProps<'button'>) {
    return (
        <button
            className={cn(
                'border-border-strong text-ink min-h-11 rounded-full border px-5 py-2.5 text-sm font-semibold',
                'hover:bg-secondary transition-colors duration-150 ease-out disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

/**
 * The 44px rule (DESIGN §Accessibility: "Touch targets ≥ 44px despite the small type").
 *
 * "Despite the small type" is the whole difficulty: these actions are 12px words, so
 * their natural hit box is about 27×16 — a third of the minimum, and they sit directly
 * on top of a card that is itself tappable. A thumb that misses "Keep" by four pixels
 * doesn't miss; it hits the card and navigates. The action felt dead and the card felt
 * hair-triggered, and it was one bug.
 *
 * Padding would fix the target and move every action row on every card, so instead the
 * target grows *around* the text: a centered, invisible 44px pseudo-element that takes
 * the taps. Nothing shifts, and there is nothing left to miss.
 *
 * Callers must keep ≥ 24px between two of these, or the boxes overlap and the fix
 * becomes a new bug — a tap that lands on the wrong action is worse than one that lands
 * on nothing.
 */
const touchTarget =
    "relative after:absolute after:top-1/2 after:left-1/2 after:h-11 after:w-full after:min-w-11 after:-translate-x-1/2 after:-translate-y-1/2 after:content-['']";

/** Underlined text action ("Take me" on non-hero cards). */
export function TextAction({ className, ...props }: React.ComponentProps<'button'>) {
    return (
        <button
            className={cn('text-ink hover:text-terracotta text-xs font-semibold underline underline-offset-[3px]', touchTarget, className)}
            {...props}
        />
    );
}

/** Quiet action ("Keep", "Not for me") — meta-colored, no underline. Interactive text is readable text (DESIGN §5): meta, never quiet/muted. */
export function QuietAction({ className, ...props }: React.ComponentProps<'button'>) {
    return <button className={cn('text-meta hover:text-ink text-xs font-medium', touchTarget, className)} {...props} />;
}

/** Calibration choice pill — selected state is filled ink, never terracotta (DESIGN §1.1 rule 1). */
export function ChoicePill({ selected = false, className, ...props }: React.ComponentProps<'button'> & { selected?: boolean }) {
    return (
        <button
            aria-pressed={selected}
            className={cn(
                'min-h-11 rounded-full border px-4 py-2 text-sm transition-colors duration-150 ease-out',
                selected ? 'border-ink bg-ink text-card font-semibold' : 'border-border-strong text-meta hover:text-ink font-medium',
                className,
            )}
            {...props}
        />
    );
}
