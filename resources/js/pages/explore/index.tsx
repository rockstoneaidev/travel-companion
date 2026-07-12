import { AppHeader, ChoicePill, EditorialLede, PrimaryPill } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { type TravelMode } from '@/types/enums';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * S2 — session start (SCREENS.md): one screen, no wizard. Geolocation is
 * asked here, in context, never on app open; "no" gets a quiet manual
 * fallback, not nagging. Only reachable with no session open — while one is
 * running, /explore is the feed (S1).
 */

interface TravelModeOption {
    value: TravelMode;
    label: string;
}

interface ExploreIndexProps {
    travelModeOptions: TravelModeOption[];
}

const TIME_CHIPS = [
    { label: '45 min', minutes: 45 },
    { label: '2 h', minutes: 120 },
    { label: '3 h', minutes: 180 },
    { label: 'All day', minutes: 480 },
];

// Liljeholmen — the test-region base (PRD §8.0); the manual-origin fallback
// when geolocation is denied or unavailable (SCREENS S2).
const FALLBACK_ORIGIN = { lat: 59.31, lng: 18.02 };

export default function ExploreIndex({ travelModeOptions }: ExploreIndexProps) {
    const [locating, setLocating] = useState(false);
    const [located, setLocated] = useState<'yes' | 'denied' | null>(null);

    const { data, setData, post, processing } = useForm({
        origin: FALLBACK_ORIGIN,
        time_budget_minutes: 180,
        travel_mode: 'walk' as TravelMode,
    });

    const useMyLocation = () => {
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setData('origin', { lat: position.coords.latitude, lng: position.coords.longitude });
                setLocated('yes');
                setLocating(false);
            },
            () => {
                setLocated('denied');
                setLocating(false);
            },
            { timeout: 10_000 },
        );
    };

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Explore" />
                <div className="mx-auto max-w-md space-y-8 px-5 py-8">
                    <AppHeader contextStamp="Stockholm" />

                    <form
                        className="space-y-8"
                        onSubmit={(event) => {
                            event.preventDefault();
                            post('/explore');
                        }}
                    >
                        <h1 className="text-headline text-ink font-serif font-medium italic">How long do you have?</h1>

                        <div className="flex flex-wrap gap-2">
                            {TIME_CHIPS.map((chip) => (
                                <ChoicePill
                                    key={chip.minutes}
                                    type="button"
                                    selected={data.time_budget_minutes === chip.minutes}
                                    onClick={() => setData('time_budget_minutes', chip.minutes)}
                                >
                                    {chip.label}
                                </ChoicePill>
                            ))}
                        </div>

                        <div className="flex flex-wrap gap-2">
                            {travelModeOptions.map((mode) => (
                                <ChoicePill
                                    key={mode.value}
                                    type="button"
                                    selected={data.travel_mode === mode.value}
                                    onClick={() => setData('travel_mode', mode.value)}
                                >
                                    {mode.label}
                                </ChoicePill>
                            ))}
                        </div>

                        <div className="space-y-2">
                            <button
                                type="button"
                                onClick={useMyLocation}
                                className="text-ink text-xs font-semibold underline underline-offset-[3px]"
                                disabled={locating}
                            >
                                {locating ? 'Finding you…' : located === 'yes' ? 'Using your location ✓' : 'Use my location'}
                            </button>
                            {located === 'denied' && (
                                <p className="text-body-card text-body">
                                    No problem — starting from Liljeholmen. Tell me where you're starting from and I'll take it from there.
                                </p>
                            )}
                        </div>

                        <div className="space-y-4">
                            <PrimaryPill type="submit" disabled={processing}>
                                Start exploring
                            </PrimaryPill>
                            <EditorialLede>I'll be quiet until something is worth it.</EditorialLede>
                        </div>
                    </form>
                </div>
            </div>
        </ProductLayout>
    );
}
