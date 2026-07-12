import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export interface TabItem {
    label: string;
    href: string;
    active?: boolean;
}

/**
 * Floating pill tab bar (DESIGN §3): fixed bottom, above safe-area insets, at every
 * width. On desktop the left edge belongs to the app sidebar, so these trip-scoped
 * tabs stay a centred pill rather than competing with it as a second left rail.
 */
export function TabBar({ tabs, className, ...props }: React.ComponentProps<'nav'> & { tabs: TabItem[] }) {
    return (
        <nav
            aria-label="Primary"
            className={cn(
                'fixed bottom-6 left-1/2 z-40 -translate-x-1/2',
                'border-border bg-card shadow-sheet flex items-center gap-1 rounded-full border px-2 py-1',
                'pb-[max(0.25rem,env(safe-area-inset-bottom))]',
                className,
            )}
            {...props}
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.label}
                    href={tab.href}
                    className={cn(
                        'min-h-11 min-w-11 content-center rounded-full px-4 py-2 text-center text-xs tracking-[.12em] uppercase',
                        tab.active ? 'text-ink font-semibold' : 'text-meta hover:text-ink font-medium',
                    )}
                    aria-current={tab.active ? 'page' : undefined}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
