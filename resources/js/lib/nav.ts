import { type NavItem, type SharedData } from '@/types';
import {
    Activity,
    BellRing,
    Bookmark,
    BookOpen,
    Compass,
    Gauge,
    LayoutGrid,
    Map,
    Navigation,
    NotebookPen,
    ScrollText,
    Shield,
    Users,
} from 'lucide-react';

/**
 * The app navigation, shared by the sidebar (app chrome) and the menu sheet on the
 * product screens — one list, so the two can't drift apart.
 */
export const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Explore',
        url: '/explore',
        icon: Compass,
    },
    {
        title: 'Kept',
        url: '/kept',
        icon: Bookmark,
    },
    {
        title: 'Journal',
        url: '/journal',
        icon: NotebookPen,
    },
    {
        title: 'Trips',
        url: '/trips',
        icon: Map,
    },
];

export function adminNavItems(permissions: SharedData['auth']['permissions']): NavItem[] {
    const items: NavItem[] = [{ title: 'Overview', url: '/admin', icon: Shield }];

    items.push({ title: 'Curation', url: '/admin/curation', icon: BookOpen });
    items.push({ title: 'World model', url: '/admin/world-model', icon: Map });

    // Superadmin-only (ADMIN §6). Gated on the permission, not merely hidden — the
    // route enforces it too; "the React component does not render it" is not access
    // control (ADMIN §3).
    if (permissions.includes('location_emulate')) {
        items.push({ title: 'Emulator', url: '/admin/emulator', icon: Navigation });
    }

    if (permissions.includes('users_view')) {
        items.push({ title: 'Users', url: '/admin/users', icon: Users });
    }
    if (permissions.includes('activity_view')) {
        items.push({ title: 'Activity', url: '/admin/activity', icon: ScrollText });
        items.push({ title: 'Interruption', url: '/admin/interruption', icon: BellRing });
    }
    if (permissions.includes('ops_view')) {
        items.push({ title: 'Horizon', url: '/horizon', icon: Gauge, external: true });
        items.push({ title: 'Pulse', url: '/pulse', icon: Activity, external: true });
    }

    return items;
}
