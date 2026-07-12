import { useEffect, useState } from 'react';

/**
 * Connectivity, and when we last managed to hear from the server (SCREENS S11).
 *
 * `navigator.onLine` is a weak signal — it means "there is a network interface",
 * not "the internet answers" — but in a French dead zone it is exactly right, and
 * it is the only signal available without hammering the network, which S11
 * explicitly forbids.
 *
 * `lastFreshAt` is when this screen last rendered from a live response. A page
 * served from the service-worker cache re-runs this code, so freshness may only
 * be recorded while we are actually online — otherwise a stale feed would happily
 * claim to be current, which is the exact failure the staleness line exists to
 * prevent.
 */

const FRESH_KEY = 'feed:fresh-at';

export function useOnline(screenKey: string): { online: boolean; lastFreshAt: Date | null } {
    const [online, setOnline] = useState(() => navigator.onLine);
    const [lastFreshAt, setLastFreshAt] = useState<Date | null>(null);

    useEffect(() => {
        const key = `${FRESH_KEY}:${screenKey}`;

        if (navigator.onLine) {
            const now = new Date();
            localStorage.setItem(key, now.toISOString());
            setLastFreshAt(now);
        } else {
            const stored = localStorage.getItem(key);
            setLastFreshAt(stored === null ? null : new Date(stored));
        }
    }, [screenKey, online]);

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);

        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return { online, lastFreshAt };
}

/** "20 minutes ago" — brand voice, not a timestamp (DESIGN §voice). */
export function humanAge(at: Date, now: Date = new Date()): string {
    const minutes = Math.max(1, Math.round((now.getTime() - at.getTime()) / 60_000));

    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;

    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;

    const days = Math.round(hours / 24);

    return `${days} day${days === 1 ? '' : 's'} ago`;
}
