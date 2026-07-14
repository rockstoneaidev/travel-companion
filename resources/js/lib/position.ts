/**
 * Telling the server where we are (E46, PRD §14.5 context events).
 *
 * The endpoint has existed since the sessions epic and nothing ever called it. That
 * is the whole reason the feed you got in Liljeholmen was still the feed you had in
 * Hornstull: the backend was not ignoring your movement, it was never told about it.
 *
 * Phase 1 rules this obeys, and must keep obeying (PRD §8.1, constraint 5):
 *
 *  - **Foreground only.** A report happens when the user is looking at the screen —
 *    on open, on focus, or when they ask for fresh picks. There is no watcher, no
 *    background sync, no `watchPosition`. The app does not follow you when it is
 *    closed, and this file is not the place that would change that.
 *  - **Never prompts.** We report a position only if geolocation permission is
 *    ALREADY granted. Asking for location because a card needs re-ranking is exactly
 *    the dark pattern PRD §16 forbids ("honest permission UX").
 */

function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Has the user already granted location — without asking them now if they haven't? */
async function permissionGranted(): Promise<boolean> {
    if (!navigator.geolocation) return false;

    // Safari shipped `permissions` late; where it is missing we cannot check without
    // prompting, so we don't report. A silent no beats an unprompted permission dialog.
    if (!navigator.permissions) return false;

    try {
        const status = await navigator.permissions.query({ name: 'geolocation' });

        return status.state === 'granted';
    } catch {
        return false;
    }
}

function currentPosition(): Promise<GeolocationPosition | null> {
    return new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (position) => resolve(position),
            () => resolve(null),
            // `maximumAge` is deliberately short: a cached fix from ten minutes ago is
            // exactly the fix that would tell the server we are still in Liljeholmen.
            { timeout: 8_000, maximumAge: 30_000, enableHighAccuracy: false },
        );
    });
}

/**
 * Report the current position for a session, if we can do so without prompting.
 *
 * Resolves `true` when a position actually reached the server — which is the caller's
 * cue to re-pull the feed, because that POST may just have re-anchored it.
 *
 * Failure is silent and returns `false`. Location is best-effort context, not a
 * transaction: a phone indoors with no fix is a normal Tuesday, and it must degrade
 * to "the feed doesn't move" rather than to an error message.
 */
export async function reportPosition(sessionId: string): Promise<boolean> {
    if (!navigator.onLine) return false;
    if (!(await permissionGranted())) return false;

    const position = await currentPosition();

    if (position === null) return false;

    try {
        const response = await fetch(`/explore/${sessionId}/context-events`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                timestamp: new Date(position.timestamp).toISOString(),
                location: {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    // The server uses this to decide whether a "move" is travel or just a
                    // bad fix (SessionAnchor). Sending it is what stops a phone with a
                    // poor view of the sky from re-ranking itself in circles.
                    accuracy_m: Math.round(position.coords.accuracy),
                },
                // Phase 1 is foreground-only, and this field says so honestly.
                app_state: 'foreground',
            }),
        });

        return response.ok;
    } catch {
        return false; // dead zone. The feed stays where it is; nothing is broken.
    }
}
