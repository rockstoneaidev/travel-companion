import AppLogoIcon from '@/components/app-logo-icon';
import { Icon } from '@/components/icon';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { adminNavItems, mainNavItems } from '@/lib/nav';
import { cn } from '@/lib/utils';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LogOut, Menu, Settings } from 'lucide-react';
import { useState } from 'react';
import { SectionLabel } from './section-label';

const itemClasses = 'text-body hover:text-ink flex min-h-11 items-center gap-3 text-sm';

/**
 * The app menu for the product screens, which carry no sidebar: a hamburger that
 * opens the same navigation as <AppSidebar />, dressed as paper (DESIGN §3). Nav
 * items come from @/lib/nav so the two menus can't drift apart.
 */
export function NavMenu({ className }: { className?: string }) {
    const { auth, name } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const cleanup = useMobileNavigation();

    const close = () => {
        setOpen(false);
        cleanup();
    };

    // Guests (e.g. the public /licenses page) have nothing to navigate to.
    if (auth.user === null) {
        return null;
    }

    const renderItem = (item: NavItem) =>
        item.external ? (
            <a key={item.title} href={item.url} target="_blank" rel="noreferrer" className={itemClasses} onClick={close}>
                {item.icon && <Icon iconNode={item.icon} />}
                {item.title}
            </a>
        ) : (
            <Link key={item.title} href={item.url} className={itemClasses} onClick={close}>
                {item.icon && <Icon iconNode={item.icon} />}
                {item.title}
            </Link>
        );

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger
                aria-label="Open menu"
                className={cn('text-ink hover:bg-card -ml-2 flex size-11 shrink-0 items-center justify-center rounded-full', className)}
            >
                <Menu className="size-5" />
            </SheetTrigger>

            <SheetContent side="left" className="bg-paper border-border flex w-72 flex-col gap-8 p-6">
                <SheetTitle asChild>
                    <div className="flex items-center gap-2">
                        <AppLogoIcon className="text-urgent size-6" />
                        <span className="text-ink font-serif text-lg leading-none font-medium italic">{name}</span>
                    </div>
                </SheetTitle>

                <nav className="flex flex-1 flex-col gap-8 overflow-y-auto">
                    <div className="flex flex-col gap-1">{mainNavItems.map(renderItem)}</div>

                    {auth.permissions.includes('admin_access') && (
                        <div className="flex flex-col gap-1">
                            <SectionLabel className="mb-1">Admin</SectionLabel>
                            {adminNavItems(auth.permissions).map(renderItem)}
                        </div>
                    )}
                </nav>

                <div className="border-border-soft flex flex-col gap-1 border-t pt-4">
                    <Link href={route('profile.edit')} className={itemClasses} onClick={close}>
                        <Settings className="size-4" />
                        Settings
                    </Link>
                    <Link href={route('logout')} method="post" as="button" className={cn(itemClasses, 'justify-start')} onClick={close}>
                        <LogOut className="size-4" />
                        Log out
                    </Link>
                </div>
            </SheetContent>
        </Sheet>
    );
}
