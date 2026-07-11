import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export interface PassoTab {
    label: string;
    href: string;
    active?: boolean;
}

/**
 * Floating pill tab bar (DESIGN §3): fixed bottom on mobile, above safe-area insets.
 * From lg it becomes a left rail (DESIGN §4 two-pane layout).
 */
export function TabBar({ tabs, className, ...props }: React.ComponentProps<'nav'> & { tabs: PassoTab[] }) {
    return (
        <nav
            aria-label="Primary"
            className={cn(
                'fixed bottom-6 left-1/2 z-40 -translate-x-1/2',
                'border-border bg-card shadow-sheet flex items-center gap-1 rounded-full border px-2 py-1',
                'pb-[max(0.25rem,env(safe-area-inset-bottom))]',
                'lg:top-0 lg:bottom-auto lg:left-0 lg:h-full lg:translate-x-0 lg:flex-col lg:items-stretch lg:justify-center lg:gap-2 lg:rounded-none lg:border-y-0 lg:border-l-0 lg:bg-transparent lg:px-6 lg:shadow-none',
                className,
            )}
            {...props}
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.label}
                    href={tab.href}
                    className={cn(
                        'min-h-11 min-w-11 content-center rounded-full px-4 py-2 text-center text-xs tracking-[.12em] uppercase lg:text-left',
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
