import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, BookOpen, Folder, Gauge, LayoutGrid, ScrollText, Shield, Users } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        url: '/dashboard',
        icon: LayoutGrid,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

function adminNavItems(permissions: SharedData['auth']['permissions']): NavItem[] {
    const items: NavItem[] = [{ title: 'Overview', url: '/admin', icon: Shield }];

    if (permissions.includes('users_view')) {
        items.push({ title: 'Users', url: '/admin/users', icon: Users });
    }
    if (permissions.includes('activity_view')) {
        items.push({ title: 'Activity', url: '/admin/activity', icon: ScrollText });
    }
    if (permissions.includes('ops_view')) {
        items.push({ title: 'Horizon', url: '/horizon', icon: Gauge, external: true });
        items.push({ title: 'Pulse', url: '/pulse', icon: Activity, external: true });
    }

    return items;
}

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {auth.permissions.includes('admin_access') && <NavMain items={adminNavItems(auth.permissions)} label="Admin" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
