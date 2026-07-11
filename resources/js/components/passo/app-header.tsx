import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

interface PassoAppHeaderProps extends React.ComponentProps<'header'> {
    /** Context stamp, e.g. "LISBON · 17:12" (city from reverse geocode, local time). */
    contextStamp?: string;
}

/**
 * Wordmark + context stamp (DESIGN §3). The wordmark renders the shared `name`
 * prop (config('app.name') ← APP_NAME) — the market name is provisional and must
 * never be hard-coded (DESIGN §1).
 */
export function PassoAppHeader({ contextStamp, className, ...props }: PassoAppHeaderProps) {
    const { name } = usePage<SharedData>().props;

    return (
        <header className={cn('flex items-baseline justify-between', className)} {...props}>
            <span className="text-wordmark text-ink font-serif font-medium lowercase italic">{name}</span>
            {contextStamp && <span className="text-facet text-meta font-medium tracking-[.14em] uppercase">{contextStamp}</span>}
        </header>
    );
}
