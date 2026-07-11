import { cn } from '@/lib/utils';

/**
 * Map pin styles (DESIGN §3) — presentational markers for the MapLibre layer (E15).
 * GO NOW pin: 34px ochre disc; standard: 18px ink disc; "you": olive-ringed dot.
 */

export function GoNowPin({ label, className, ...props }: React.ComponentProps<'div'> & { label: string }) {
    return (
        <div className={cn('flex flex-col items-center gap-1.5', className)} {...props}>
            <div className="bg-urgent shadow-urgent ring-card grid size-[34px] place-items-center rounded-full ring-[3px]">
                <div className="bg-card size-2.5 rounded-full" />
            </div>
            <span className="bg-card text-gonow text-urgent-deep shadow-card rounded-full px-2.5 py-0.5 font-bold tracking-[.14em] uppercase">
                {label}
            </span>
        </div>
    );
}

export function PlacePin({ label, className, ...props }: React.ComponentProps<'div'> & { label: string }) {
    return (
        <div className={cn('flex flex-col items-center gap-1', className)} {...props}>
            <div className="bg-ink ring-card size-[18px] rounded-full ring-[2.5px]" />
            <span className="bg-card text-facet text-meta shadow-card rounded-full px-2 py-0.5 font-medium lowercase">{label}</span>
        </div>
    );
}

export function YouMarker({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div className={cn('flex flex-col items-center gap-1', className)} {...props}>
            <div className="bg-card ring-olive size-[13px] rounded-full ring-[3px]" />
            <span className="bg-card text-facet text-meta shadow-card rounded-full px-2 py-0.5 font-medium lowercase">you</span>
        </div>
    );
}
