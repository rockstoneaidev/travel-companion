import { cn } from '@/lib/utils';
import { PrimaryPill } from './buttons';
import { SectionLabel } from './section-label';

export interface PeekSheetProps {
    label: string;
    /** Right-aligned against the label — "6 min walk". */
    meta: string;
    title: string;
    /** One line. If it wraps, it is not a peek any more. */
    note: string;
    urgent?: boolean;
    onTakeMe?: () => void;
    /** Tap-through to S4. The sheet body is the target; the pill is not. */
    onOpen?: () => void;
    onDismiss?: () => void;
    className?: string;
}

/**
 * The floating peek sheet (SCREENS S3): what a pin says when you tap it.
 *
 * It is a peek, not the detail screen — one label, one title, one line, one action.
 * Everything else is behind the tap-through, which is why the body is the target and
 * only *Take me* stops the bubble.
 */
export function PeekSheet({ label, meta, title, note, urgent = false, onTakeMe, onOpen, onDismiss, className }: PeekSheetProps) {
    return (
        <div
            className={cn(
                'bg-card shadow-sheet absolute inset-x-3 z-20 mx-auto max-w-md cursor-pointer rounded-[14px] p-4',
                // Clears the floating tab bar, which is fixed to the same corner of the
                // screen — and stays in the app's editorial column instead of stretching
                // to the full width of a desktop map.
                'bottom-[max(6rem,calc(env(safe-area-inset-bottom)+5.5rem))]',
                urgent ? 'border-urgent border-[1.5px]' : 'border-border border',
                className,
            )}
            role="button"
            tabIndex={0}
            onClick={() => onOpen?.()}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') onOpen?.();
                if (event.key === 'Escape') onDismiss?.();
            }}
        >
            <div className="flex items-baseline justify-between gap-3">
                <SectionLabel className={cn(urgent && 'text-urgent-deep')}>{label}</SectionLabel>
                <span className="text-meta-row text-meta font-medium">{meta}</span>
            </div>

            <h3 className="text-ink mt-2 font-serif text-lg font-medium">{title}</h3>
            <p className="text-body-card text-body mt-1 truncate">{note}</p>

            <div className="mt-3 flex justify-end">
                <PrimaryPill onClick={(event) => (event.stopPropagation(), onTakeMe?.())}>Take me</PrimaryPill>
            </div>
        </div>
    );
}
