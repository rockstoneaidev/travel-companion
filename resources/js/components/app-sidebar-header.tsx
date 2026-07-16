import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The top bar for the sidebar layout (dashboard, trips, admin).
 *
 * Sticky, always: the hamburger and the wordmark must stay reachable however far the page
 * scrolls — a menu you have to scroll back up to find is a menu people stop using. The
 * wordmark is `md:hidden` because from md up the fixed sidebar already carries it; below md
 * the sidebar is off-canvas, so this is where the brand and the way-in live.
 */
export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const { name } = usePage<SharedData>().props;

    return (
        <header className="border-sidebar-border/50 bg-background/95 supports-[backdrop-filter]:bg-background/80 sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b px-6 backdrop-blur transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <SidebarTrigger className="-ml-1" />
            <span className="font-serif font-medium lowercase italic md:hidden">{name}</span>
            <Breadcrumbs breadcrumbs={breadcrumbs} />
        </header>
    );
}
