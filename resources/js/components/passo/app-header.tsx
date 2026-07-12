import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { PassoNavMenu } from './nav-menu';

interface PassoAppHeaderProps extends React.ComponentProps<'header'> {
    /** Context stamp, e.g. "LISBON · 17:12" (city from reverse geocode, local time). */
    contextStamp?: string;
}

/**
 * Menu + wordmark + context stamp (DESIGN §3). The wordmark renders the shared `name`
 * prop (config('app.name') ← APP_NAME) — the market name is provisional and must
 * never be hard-coded (DESIGN §1). The hamburger is the only way into the app
 * navigation on these screens: they carry no sidebar.
 */
export function PassoAppHeader({ contextStamp, className, ...props }: PassoAppHeaderProps) {
    const { name } = usePage<SharedData>().props;

    return (
        <header className={cn('flex items-center justify-between gap-3', className)} {...props}>
            <div className="flex min-w-0 items-center gap-2">
                <PassoNavMenu />
                <span className="text-wordmark text-ink truncate font-serif font-medium lowercase italic">{name}</span>
            </div>
            {contextStamp && <span className="text-facet text-meta shrink-0 font-medium tracking-[.14em] uppercase">{contextStamp}</span>}
        </header>
    );
}
