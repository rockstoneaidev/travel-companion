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
                'bg-terracotta text-on-terracotta rounded-full px-5 py-2.5 text-sm font-bold',
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
                'border-border-strong text-ink rounded-full border px-5 py-2.5 text-sm font-semibold',
                'hover:bg-secondary transition-colors duration-150 ease-out disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

/** Underlined text action ("Take me" on non-hero cards). */
export function TextAction({ className, ...props }: React.ComponentProps<'button'>) {
    return <button className={cn('text-ink hover:text-terracotta text-xs font-semibold underline underline-offset-[3px]', className)} {...props} />;
}

/** Quiet action ("Keep", "Not for me") — meta-colored, no underline. Interactive text is readable text (DESIGN §5): meta, never quiet/muted. */
export function QuietAction({ className, ...props }: React.ComponentProps<'button'>) {
    return <button className={cn('text-meta hover:text-ink text-xs font-medium', className)} {...props} />;
}

/** Calibration choice pill — selected state is filled ink, never terracotta (DESIGN §1.1 rule 1). */
export function ChoicePill({ selected = false, className, ...props }: React.ComponentProps<'button'> & { selected?: boolean }) {
    return (
        <button
            aria-pressed={selected}
            className={cn(
                'rounded-full border px-4 py-2 text-sm transition-colors duration-150 ease-out',
                selected ? 'border-ink bg-ink text-card font-semibold' : 'border-border-strong text-meta hover:text-ink font-medium',
                className,
            )}
            {...props}
        />
    );
}
