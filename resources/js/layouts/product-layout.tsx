import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';

/**
 * Shell for the product screens (session start, feed, opportunity detail).
 *
 * From md up they get the same left sidebar as the dashboard — one navigation
 * for the whole app. Below md the sidebar hides itself (ui/sidebar) and the
 * hamburger in <NavMenu /> is the way in, on the top left of every screen.
 */
export default function ProductLayout({ children }: { children: React.ReactNode }) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="bg-paper">
                {children}
            </AppContent>
        </AppShell>
    );
}
