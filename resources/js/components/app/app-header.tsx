import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { NavMenu } from './nav-menu';

interface AppHeaderProps extends React.ComponentProps<'header'> {
    /** Context stamp, e.g. "LISBON · 17:12" (city from reverse geocode, local time). */
    contextStamp?: string;
}

/**
 * Menu + wordmark + context stamp (DESIGN §3). The wordmark renders the shared `name`
 * prop (config('app.name') ← APP_NAME) — the market name is provisional and must
 * never be hard-coded (DESIGN §1). The hamburger sits on the left and is the way
 * into the navigation on mobile; from md up <ProductLayout />'s sidebar takes over.
 */
export function AppHeader({ contextStamp, className, ...props }: AppHeaderProps) {
    const { name } = usePage<SharedData>().props;

    return (
        <header
            className={cn(
                'bg-paper/95 supports-[backdrop-filter]:bg-paper/80 sticky top-0 z-30 flex items-center justify-between gap-3 backdrop-blur',
                className,
            )}
            {...props}
        >
            <div className="flex min-w-0 items-center gap-2">
                <NavMenu />
                <span className="text-wordmark text-ink truncate font-serif font-medium lowercase italic">{name}</span>
            </div>
            {contextStamp && <span className="text-facet text-meta shrink-0 font-medium tracking-[.14em] uppercase">{contextStamp}</span>}
        </header>
    );
}
