import { urgencyFraction } from '@/lib/passo';
import { cn } from '@/lib/utils';
import { type UrgencyWindow } from '@/types/passo';

/**
 * The light-remaining ring gauge.
 *
 * Deliberately NOT exported: DESIGN §1.1 rule 3 — "the ring never appears without
 * its label; alone it reads as a loading spinner." The only way to render a ring is
 * `<UrgencyHeader>`, which always renders "GO NOW" beside it. Keeping the rule in the
 * module boundary is cheaper than remembering it.
 *
 * It draws its arc once on entry (600ms) and is then static — it must never pulse or
 * spin. `prefers-reduced-motion` removes the draw entirely (app.css).
 */
function LightRing({ fraction, size = 38 }: { fraction: number; size?: number }) {
    const stroke = 3;
    const radius = (size - stroke) / 2;
    const circumference = 2 * Math.PI * radius;

    return (
        <svg
            width={size}
            height={size}
            viewBox={`0 0 ${size} ${size}`}
            aria-hidden="true"
            className="shrink-0"
            style={{ '--ring-circumference': circumference } as React.CSSProperties}
        >
            {/* Track */}
            <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--p-urgent-track)" strokeWidth={stroke} />
            {/* Remaining arc — starts at -90° (12 o'clock) */}
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="var(--p-urgent)"
                strokeWidth={stroke}
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={circumference * (1 - fraction)}
                transform={`rotate(-90 ${size / 2} ${size / 2})`}
                className="animate-ring-draw"
            />
        </svg>
    );
}

export interface UrgencyHeaderProps {
    window: UrgencyWindow;
    className?: string;
}

/**
 * "◔ GO NOW / ~40 min of light left" — the urgent card's header row.
 *
 * Urgency is never colour-only (DESIGN §5): the caps label, the ring, the 1.5px
 * border and the shadow all co-occur, so the ochre is never the sole carrier.
 */
export function UrgencyHeader({ window, className }: UrgencyHeaderProps) {
    const fraction = urgencyFraction(window.remaining_minutes, window.total_minutes);

    return (
        <div className={cn('flex items-center gap-2.5', className)}>
            <LightRing fraction={fraction} />
            <div className="flex flex-col gap-0.5">
                <span className="text-urgent-deep text-gonow tracking-gonow font-bold uppercase">Go now</span>
                <span className="text-meta text-note">{window.note}</span>
            </div>
        </div>
    );
}
