import { requestCurrentPosition } from '@/lib/position';

export interface Essential {
    name: string;
    type: string;
    distance_m: number;
    lat: number;
    lng: number;
}

export interface EssentialsResult {
    located: boolean;
    items: Essential[];
}

/**
 * The nearest essential amenities to where the traveller is right now.
 *
 * Prompts for location, on purpose: you tapped "I need a…", so the permission dialog is
 * expected, not the dark pattern §16 forbids. Returns `located: false` if we cannot get a fix
 * (declined, or no geolocation) so the sheet can say what to do instead of showing an empty
 * list that looks like "there are none".
 */
export async function fetchEssentials(): Promise<EssentialsResult> {
    const position = await requestCurrentPosition();

    if (!position) {
        return { located: false, items: [] };
    }

    try {
        const params = new URLSearchParams({
            lat: String(position.coords.latitude),
            lng: String(position.coords.longitude),
        });

        const response = await fetch(`/essentials?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return { located: true, items: [] };
        }

        const body = (await response.json()) as { data?: Essential[] };

        return { located: true, items: body.data ?? [] };
    } catch {
        return { located: true, items: [] };
    }
}
