import { reportPosition } from '@/lib/position';
import { type ContextSource } from '@/types/enums';
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
 *
 * ---------------------------------------------------------------------------
 * EMULATED SESSIONS DO NOT REPORT THIS BROWSER'S POSITION.
 * ---------------------------------------------------------------------------
 *
 * They must not, and the reason is a bug we shipped and then watched happen (E47,
 * 2026-07-14): the position emulator renders the real feed in an iframe, this hook ran
 * inside it, read the OPERATOR'S ACTUAL GEOLOCATION, and posted it into the emulated
 * session. The founder was sitting in Liljeholmen driving a pin across Vasastaden; the
 * feed re-anchored onto Liljeholmen, and the simulation quietly became a report of
 * where his body was.
 *
 * An emulated session's position comes from the pin, and from nowhere else. So here the
 * pane only ever PULLS — which is also what makes the pipeline visibly react as the pin
 * moves: every poll is an ordinary feed request, and the server re-anchors off the
 * context events the emulator has been posting. Same code path, same server decision;
 * the client simply stops volunteering a position nobody asked it for.
 */
export function useLivingFeed(
    sessionId: string,
    active: boolean,
    source: ContextSource = 'device',
    /**
     * We are ingesting this area right now (E48). Poll, because the feed is about to
     * become non-empty on its own — the boxes nearest the user land first, so the wait
     * is a minute or two, not the forty-five the whole region takes.
     */
    learning = false,
): { refresh: () => void } {
    const emulated = source === 'emulated';

    // A pull is: report position → re-pull the feed. Guarded, because 'focus' fires
    // more often than you would think (alt-tab, devtools, autofill), and two ranks
    // racing each other would serve two batches for one walk.
    const pulling = useRef(false);

    const pull = useCallback(async () => {
        if (!active || pulling.current) return;

        pulling.current = true;

        try {
            if (emulated) {
                // Never volunteer where this browser is. Just ask what the pin's session
                // serves now.
                router.reload({ only: ['opportunities', 'serve', 'coverage'] });

                return;
            }

            const reported = await reportPosition(sessionId);

            // Nothing reached the server — permission not granted, no fix, or offline.
            // There is nothing new to ask for, so don't ask: a reload here would be a
            // request that reliably changes nothing.
            if (!reported) return;

            router.reload({ only: ['opportunities', 'serve', 'coverage'] });
        } finally {
            pulling.current = false;
        }
    }, [sessionId, active, emulated]);

    useEffect(() => {
        void pull();

        const onFocus = () => void pull();

        window.addEventListener('focus', onFocus);

        /*
         * The emulator's pane polls; a real phone does not.
         *
         * On a phone this would be the interruption the product exists to avoid. In the
         * console it is the point: the operator is WATCHING the pipeline react, and a
         * feed that only refreshed when the iframe happened to get focus made the tool
         * look broken — the pin crossed the city and the scouts never fired, because
         * nothing ever pulled (E47).
         */
        const timer = emulated || learning ? window.setInterval(() => void pull(), emulated ? 3_000 : 10_000) : null;

        return () => {
            window.removeEventListener('focus', onFocus);

            if (timer !== null) window.clearInterval(timer);
        };
    }, [pull, emulated, learning]);

    /**
     * "Fresh picks from here" — the explicit version, which takes the server's
     * refresh route rather than a plain re-pull, because the user asking is not the
     * same event as the user arriving. It re-serves whether or not they have moved.
     */
    const refresh = useCallback(() => {
        void (async () => {
            // Report first, so the refresh ranks from where they are standing and not
            // from wherever the last batch was anchored. Never for an emulated session:
            // its "here" is the pin, not this browser.
            if (!emulated) {
                await reportPosition(sessionId);
            }

            router.post(`/explore/${sessionId}/refresh`, {}, { preserveScroll: true, only: ['opportunities', 'serve', 'coverage'] });
        })();
    }, [sessionId, emulated]);

    return { refresh };
}
