import { reportPosition } from '@/lib/position';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';

/**
 * The pull half of the living feed (E46, PRD §8.1).
 *
 * "Re-opening the app yields a fresh menu" is a two-step conversation the client was
 * only ever having with itself: tell the server where you are, then ask for the feed
 * again. The server does the rest — it decides whether you have moved far enough to
 * deserve a new batch (SessionAnchor), and it tops the menu up if dismissals have
 * thinned it.
 *
 * WHEN we do this is the whole design, and it is deliberately boring:
 *
 *  - on open, and
 *  - when the tab regains focus — i.e. when you take the phone out of your pocket,
 *    which is exactly the moment you have finished walking somewhere.
 *
 * Not on an interval. A timer that re-pulls while the user is reading is how a feed
 * starts moving cards out from under a thumb, and the product's whole claim is that
 * it interrupts less than the alternatives. Phase 1 is pull-based (constraint 5):
 * this hook runs only while the screen is open and looked at.
 */
export function useLivingFeed(sessionId: string, active: boolean): { refresh: () => void } {
    // A pull is: report position → re-pull the feed. Guarded, because 'focus' fires
    // more often than you would think (alt-tab, devtools, autofill), and two ranks
    // racing each other would serve two batches for one walk.
    const pulling = useRef(false);

    const pull = useCallback(async () => {
        if (!active || pulling.current) return;

        pulling.current = true;

        try {
            const reported = await reportPosition(sessionId);

            // Nothing reached the server — permission not granted, no fix, or offline.
            // There is nothing new to ask for, so don't ask: a reload here would be a
            // request that reliably changes nothing.
            if (!reported) return;

            router.reload({ only: ['opportunities', 'serve'] });
        } finally {
            pulling.current = false;
        }
    }, [sessionId, active]);

    useEffect(() => {
        void pull();

        const onFocus = () => void pull();

        window.addEventListener('focus', onFocus);

        return () => window.removeEventListener('focus', onFocus);
    }, [pull]);

    /**
     * "Fresh picks from here" — the explicit version, which takes the server's
     * refresh route rather than a plain re-pull, because the user asking is not the
     * same event as the user arriving. It re-serves whether or not they have moved.
     */
    const refresh = useCallback(() => {
        void (async () => {
            // Report first, so the refresh ranks from where they are standing and not
            // from wherever the last batch was anchored.
            await reportPosition(sessionId);

            router.post(`/explore/${sessionId}/refresh`, {}, { preserveScroll: true, only: ['opportunities', 'serve'] });
        })();
    }, [sessionId]);

    return { refresh };
}
