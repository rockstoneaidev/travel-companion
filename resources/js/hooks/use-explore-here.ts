import { requestCurrentPosition } from '@/lib/position';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

/**
 * "Explore my position" — the whole start-from-here loop in one tap.
 *
 * Starts a fresh session where the traveller is standing, with sensible defaults, and lands on
 * the live feed. The session clusters into a trip on its own and the feed pipeline gathers the
 * local information, so there is nothing to fill in — you press it and you are exploring. It
 * prompts for location, because you asked; declined, it says so instead of doing nothing.
 */
export function useExploreHere() {
    const [locating, setLocating] = useState(false);
    const [denied, setDenied] = useState(false);

    const go = useCallback(async () => {
        setLocating(true);
        setDenied(false);

        const position = await requestCurrentPosition();

        if (!position) {
            setLocating(false);
            setDenied(true);

            return;
        }

        router.post('/explore', {
            origin: { lat: position.coords.latitude, lng: position.coords.longitude },
            time_budget_minutes: 180,
            travel_mode: 'walk',
        });
        // On success Inertia navigates to the live feed; `locating` stays true until this
        // component unmounts, so the button does not flash back to its label mid-redirect.
    }, []);

    return { go, locating, denied };
}
