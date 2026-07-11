import { AppHeader } from '@/components/passo/app-header';
import { PASSO_TABS, SideRail, TabBar } from '@/components/passo/tab-bar';
import { cn } from '@/lib/utils';
import { type PassoTab } from '@/types/passo';
import { type ReactNode } from 'react';

export interface PassoShellProps {
    children: ReactNode;
    /** Active tab `href` — see `PASSO_TABS`. */
    active?: string;
    context?: { city: string; time: string };
    /**
     * The second pane (≥1024px only): the MAP where it adds value (DESIGN §4).
     * Ignored below 1024px, where MAP is its own tab.
     */
    aside?: ReactNode;
    tabs?: PassoTab[];
    onSelect?: (tab: PassoTab) => void;
    className?: string;
}

/**
 * The three responsive frames (DESIGN §4). One layout, three shapes:
 *
 *   ~400px   the phone. The primary surface, installed as a PWA on the trip.
 *   ≥640px   the same phone layout, *centered* in a ~28rem column with generous paper
 *            margins and the bottom tab bar retained. Never a half-stretched hybrid.
 *   ≥1024px  persistent left rail replaces the tab bar; the content column stays ~28rem
 *            and an optional MAP pane sits to its right. The column *is* the design —
 *            cards are never stretched full-width.
 */
export function PassoShell({ children, active = PASSO_TABS[0].href, context, aside, tabs, onSelect, className }: PassoShellProps) {
    return (
        <div className={cn('bg-paper text-ink min-h-svh lg:flex', className)}>
            <SideRail tabs={tabs} active={active} onSelect={onSelect} context={context} />

            <div className="flex min-w-0 flex-1 lg:justify-start">
                <main
                    className={cn(
                        // Screen padding 18–20px; bottom padding clears the floating tab bar.
                        'mx-auto w-full max-w-md px-5 pb-[90px]',
                        // On the rail layout the tab bar is gone, so the bottom padding relaxes.
                        'lg:mx-0 lg:pb-10',
                        aside ? 'lg:border-border lg:border-r lg:px-8' : 'lg:px-8',
                    )}
                >
                    {/* The rail carries the wordmark and stamp at ≥1024px, so the header steps aside. */}
                    <AppHeader context={context} className="lg:hidden" />
                    {children}
                </main>

                {aside ? <aside className="hidden min-w-0 flex-1 lg:block">{aside}</aside> : null}
            </div>

            <TabBar tabs={tabs} active={active} onSelect={onSelect} />
        </div>
    );
}
