import { requestCurrentPosition } from '@/lib/position';
import { router } from '@inertiajs/react';

/**
 * Start a planned trip.
 *
 * If the trip has a location, the server explores from the anchor the planner chose. If it
 * doesn't, we ask the browser where you are and start from there — the "I planned this by name
 * and now I'm standing in it" case (Fjäderholmarna). A prompt here is expected: you pressed the
 * button. Decline it and the server explains the trip still needs a location.
 */
export async function startPlannedTrip(tripId: string, hasLocation: boolean): Promise<void> {
    if (hasLocation) {
        router.post(`/trips/${tripId}/start`);

        return;
    }

    const position = await requestCurrentPosition();

    router.post(`/trips/${tripId}/start`, position ? { lat: position.coords.latitude, lng: position.coords.longitude } : {});
}
