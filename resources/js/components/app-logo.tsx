import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

/**
 * Mark + wordmark. The wordmark renders the shared `name` prop (config('app.name')
 * ← APP_NAME) — the market name is provisional, never hard-code it (DESIGN §1).
 */
export default function AppLogo() {
    const { name } = usePage<SharedData>().props;

    return (
        <>
            <div className="text-urgent flex aspect-square size-8 shrink-0 items-center justify-center">
                <AppLogoIcon className="size-7" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="text-ink truncate font-serif text-[1.0625rem] leading-none font-medium italic">{name}</span>
            </div>
        </>
    );
}
