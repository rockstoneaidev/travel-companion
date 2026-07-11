import { cn } from '@/lib/utils';

/**
 * The map pin set (DESIGN §3).
 *
 * These are plain DOM/CSS markers, deliberately framework-free inside: MapLibre takes an
 * HTMLElement per marker, so the map screen (E9) renders these and hands over the node.
 * The map bundle itself lazy-loads on first MAP open — it never enters the feed's
 * critical path — so the pin styles must not drag MapLibre in as a dependency.
 *
 * Urgency is never colour-only here either: the GO NOW pin is larger, ringed, shadowed,
 * and its label chip is uppercase.
 */

export type MapPinKind = 'urgent' | 'standard' | 'you';

export interface MapPinProps {
    kind?: MapPinKind;
    /** The chip beside the pin. The "you" marker has none. */
    label?: string;
    className?: string;
}

export function MapPin({ kind = 'standard', label, className }: MapPinProps) {
    if (kind === 'you') {
        return (
            <span
                aria-label="You are here"
                className={cn('bg-card ring-olive shadow-card inline-block size-[13px] rounded-full ring-[3px]', className)}
            />
        );
    }

    const isUrgent = kind === 'urgent';

    return (
        <span className={cn('inline-flex items-center gap-1.5', className)}>
            <span
                aria-hidden="true"
                className={cn(
                    'ring-card inline-block rounded-full',
                    isUrgent ? 'bg-urgent shadow-urgent size-[34px] ring-[3px]' : 'bg-ink shadow-card size-[18px] ring-[2.5px]',
                )}
            />
            {label ? (
                <span
                    className={cn(
                        'bg-card border-border rounded-pill shadow-card border px-2 py-0.5 whitespace-nowrap',
                        isUrgent ? 'text-urgent-deep text-gonow tracking-gonow font-bold uppercase' : 'text-ink text-micro font-medium',
                    )}
                >
                    {label}
                </span>
            ) : null}
        </span>
    );
}

/**
 * The warm paper map canvas. The real thing is MapLibre GL + vector tiles with a custom
 * Passo style (DESIGN §3) — raster OSM tiles cannot be restyled beyond crude CSS filters.
 * This is the styled *placeholder* the kit ships: correct colours, correct pin framing,
 * no 200KB map bundle. E9 swaps the canvas, keeps the pins.
 */
export function MapCanvasPlaceholder({ className, children }: { className?: string; children?: React.ReactNode }) {
    return (
        <div
            className={cn('bg-map-bg rounded-block border-border relative overflow-hidden border', className)}
            style={{
                // A street grid, not a stretched shape: every size below is absolute, so the
                // canvas reads the same in a 320px card and a full-height desktop pane.
                backgroundImage: [
                    'linear-gradient(var(--p-map-green) 0 0)',
                    'repeating-linear-gradient(0deg, var(--p-map-road) 0 3px, transparent 3px 92px)',
                    'repeating-linear-gradient(90deg, var(--p-map-road) 0 3px, transparent 3px 118px)',
                ].join(','),
                backgroundSize: '150px 110px, auto, auto',
                backgroundPosition: '62% 18%, 0 24px, 34px 0',
                backgroundRepeat: 'no-repeat, repeat, repeat',
            }}
        >
            {children}
        </div>
    );
}

/**
 * The attribution line the map must carry (SCREENS S3) — an ODbL obligation, not
 * decoration. The full text lives on the attributions screen.
 */
export function MapAttribution({ className }: { className?: string }) {
    return (
        <p className={cn('text-meta text-stamp absolute right-2 bottom-2', className)}>
            © OpenStreetMap contributors ·{' '}
            <a href="/attributions" className="underline [text-underline-offset:2px]">
                licences
            </a>
        </p>
    );
}
