import { cn } from '@/lib/utils';

interface GoNowBadgeProps extends React.ComponentProps<'div'> {
    /** Fraction of the urgency window remaining, 0–1 (DESIGN §3: arc = remaining / window). */
    remaining: number;
    /** The time note under the label, e.g. "~40 min of light left". Required — the ring never appears without its label (DESIGN §1.1 rule 3). */
    note: string;
}

/**
 * The GO NOW treatment: light-remaining ring + label, always together.
 * The ring animates its arc once on entry (600ms), then is static — never pulses or spins.
 */
export function GoNowBadge({ remaining, note, className, ...props }: GoNowBadgeProps) {
    const r = 16;
    const c = 2 * Math.PI * r;
    const fraction = Math.min(1, Math.max(0, remaining));

    return (
        <div className={cn('flex items-center gap-3', className)} {...props}>
            <svg width="38" height="38" viewBox="0 0 38 38" aria-hidden="true" className="shrink-0 -rotate-90">
                <circle cx="19" cy="19" r={r} fill="none" stroke="var(--urgent-track)" strokeWidth="3" />
                <circle
                    cx="19"
                    cy="19"
                    r={r}
                    fill="none"
                    stroke="var(--urgent)"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - fraction)}
                    className="motion-safe:animate-[go-now-arc_600ms_ease-out]"
                    style={{ ['--go-now-circumference' as string]: `${c}px` }}
                />
            </svg>
            <div>
                <div className="text-gonow text-urgent-deep font-bold tracking-[.18em] uppercase">Go now</div>
                <div className="text-meta-row text-meta font-medium">{note}</div>
            </div>
        </div>
    );
}
