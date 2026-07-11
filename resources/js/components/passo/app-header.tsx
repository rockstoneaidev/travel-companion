import { ContextStamp } from '@/components/passo/meta';
import { useAppName } from '@/hooks/use-app-name';
import { cn } from '@/lib/utils';

/**
 * The wordmark. Newsreader italic 500.
 *
 * The string comes from `APP_NAME` via the shared Inertia prop — it is *never* hard
 * coded. "Passo" is the internal codename of the design system; the market-facing name
 * is provisional ("Travel Companion") and must remain a config change (DESIGN §1).
 */
export function Wordmark({ className }: { className?: string }) {
    const appName = useAppName();

    return <span className={cn('text-ink text-wordmark font-serif font-medium italic', className)}>{appName}</span>;
}

export interface AppHeaderProps {
    /** Reverse-geocoded city + local time, e.g. "LISBON" / "17:12". Omitted before a session exists. */
    context?: { city: string; time: string };
    className?: string;
}

/** Global chrome on every tab screen: wordmark left, context stamp right (SCREENS.md). */
export function AppHeader({ context, className }: AppHeaderProps) {
    return (
        <header className={cn('flex items-baseline justify-between gap-4 py-4', className)}>
            <Wordmark />
            {context ? <ContextStamp city={context.city} time={context.time} /> : null}
        </header>
    );
}
