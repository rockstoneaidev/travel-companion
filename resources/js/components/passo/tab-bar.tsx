import { Wordmark } from '@/components/passo/app-header';
import { ContextStamp } from '@/components/passo/meta';
import { cn } from '@/lib/utils';
import { type PassoTab } from '@/types/passo';
import { Link } from '@inertiajs/react';

/** NOW · MAP · KEPT · JOURNAL (DESIGN §3). Four, and only four. */
export const PASSO_TABS: PassoTab[] = [
    { label: 'Now', href: '/' },
    { label: 'Map', href: '/map' },
    { label: 'Kept', href: '/kept' },
    { label: 'Journal', href: '/journal' },
];

interface TabNavProps {
    tabs?: PassoTab[];
    /** The active tab's `href`. */
    active?: string;
    /**
     * When provided, tabs render as buttons and call this instead of navigating.
     * The demo gallery and the map screen (which switches panes in place) use it.
     */
    onSelect?: (tab: PassoTab) => void;
    className?: string;
}

function tabClasses(isActive: boolean) {
    return cn(
        'rounded-pill tap-target text-tab tracking-facet flex flex-1 items-center justify-center px-3 uppercase transition-colors duration-200',
        isActive ? 'text-ink font-semibold' : 'text-meta hover:text-ink font-medium',
    );
}

/**
 * The floating pill tab bar — mobile and mid widths.
 *
 * Fixed 24px from the bottom, above the safe-area inset. On ≥1024px it is replaced by
 * `<SideRail>` (DESIGN §4), so it hides itself there rather than making every screen
 * remember to.
 */
export function TabBar({ tabs = PASSO_TABS, active, onSelect, className }: TabNavProps) {
    return (
        <nav
            aria-label="Primary"
            className={cn(
                'bg-card border-border rounded-pill shadow-sheet fixed inset-x-0 z-40 mx-auto flex w-[min(22rem,calc(100%-2.5rem))] items-center gap-1 border p-1 lg:hidden',
                className,
            )}
            style={{ bottom: 'calc(1.5rem + env(safe-area-inset-bottom, 0px))' }}
        >
            {tabs.map((tab) => {
                const isActive = tab.href === active;

                return onSelect ? (
                    <button
                        key={tab.href}
                        type="button"
                        aria-current={isActive ? 'page' : undefined}
                        onClick={() => onSelect(tab)}
                        className={tabClasses(isActive)}
                    >
                        {tab.label}
                    </button>
                ) : (
                    <Link key={tab.href} href={tab.href} aria-current={isActive ? 'page' : undefined} className={tabClasses(isActive)}>
                        {tab.label}
                    </Link>
                );
            })}
        </nav>
    );
}

export interface SideRailProps extends TabNavProps {
    context?: { city: string; time: string };
}

/**
 * Desktop / iPad landscape (≥1024px): the tab bar becomes a persistent left rail
 * carrying the wordmark, the four tabs and the context stamp (DESIGN §4).
 */
export function SideRail({ tabs = PASSO_TABS, active, onSelect, context, className }: SideRailProps) {
    return (
        <div className={cn('border-border hidden w-56 shrink-0 flex-col gap-8 border-r px-6 py-6 lg:flex', className)}>
            <Wordmark />

            <nav aria-label="Primary" className="flex flex-col gap-1">
                {tabs.map((tab) => {
                    const isActive = tab.href === active;
                    const classes = cn(
                        'rounded-pill tap-target text-tab tracking-facet flex items-center px-4 uppercase transition-colors duration-200',
                        isActive ? 'bg-border-soft text-ink font-semibold' : 'text-meta hover:text-ink font-medium',
                    );

                    return onSelect ? (
                        <button
                            key={tab.href}
                            type="button"
                            aria-current={isActive ? 'page' : undefined}
                            onClick={() => onSelect(tab)}
                            className={classes}
                        >
                            {tab.label}
                        </button>
                    ) : (
                        <Link key={tab.href} href={tab.href} aria-current={isActive ? 'page' : undefined} className={classes}>
                            {tab.label}
                        </Link>
                    );
                })}
            </nav>

            {context ? <ContextStamp city={context.city} time={context.time} className="mt-auto" /> : null}
        </div>
    );
}
