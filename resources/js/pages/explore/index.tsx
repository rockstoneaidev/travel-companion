import { AppHeader, ChoicePill, EditorialLede, PlaceSearch, PrimaryPill, QuietAction, type PlaceSuggestion } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { type TravelMode } from '@/types/enums';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * S2 — session start (SCREENS.md): one screen, no wizard. Geolocation is
 * asked here, in context, never on app open; "no" gets a quiet manual
 * fallback, not nagging. Only reachable with no session open — while one is
 * running, /explore is the feed (S1).
 *
 * There is no default origin. Without a start point the product is dead, so
 * the honest states are "we know where you are" and "tell us" — never a guess.
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

type LocationState = 'idle' | 'locating' | 'located' | 'manual';

type Point = { lat: number; lng: number };

export default function ExploreIndex({ travelModeOptions }: ExploreIndexProps) {
    const [state, setState] = useState<LocationState>('idle');
    const [originLabel, setOriginLabel] = useState<string | null>(null);
    const [destinationLabel, setDestinationLabel] = useState<string | null>(null);
    const [headingSomewhere, setHeadingSomewhere] = useState(false);

    const { data, setData, post, processing } = useForm({
        origin: null as Point | null,
        destination_point: null as Point | null,
        time_budget_minutes: 180,
        travel_mode: 'walk' as TravelMode,
    });

    const useMyLocation = () => {
        if (!navigator.geolocation) {
            setState('manual');

            return;
        }

        setState('locating');
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setData('origin', { lat: position.coords.latitude, lng: position.coords.longitude });
                setOriginLabel('your location');
                setState('located');
            },
            // Denied, or the fix timed out. Both mean the same thing to the user:
            // we cannot place you, so ask — once, and without nagging.
            () => setState('manual'),
            { timeout: 10_000 },
        );
    };

    const chooseDestination = (place: PlaceSuggestion) => {
        setData('destination_point', place.location);
        setDestinationLabel(place.name);
    };

    const clearDestination = () => {
        setData('destination_point', null);
        setDestinationLabel(null);
        setHeadingSomewhere(false);
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

                        <OriginField
                            state={state}
                            originLabel={originLabel}
                            onUseMyLocation={useMyLocation}
                            onChoosePlace={(place) => {
                                setData('origin', place.location);
                                setOriginLabel(place.name);
                            }}
                            onStartOver={() => {
                                setData('origin', null);
                                setOriginLabel(null);
                                setState('manual');
                            }}
                        />

                        {/*
                         * Optional destination (SCREENS S2). Collapsed by default —
                         * most sessions are a wander, not a commute. When set, the
                         * session becomes a "route" context and route_fit enters
                         * the composite (SCORING §6).
                         */}
                        <div className="space-y-2">
                            {destinationLabel !== null ? (
                                <>
                                    <p className="text-body-card text-body">
                                        Heading to <span className="text-ink font-semibold">{destinationLabel}</span>. I'll look for things on
                                        the way.
                                    </p>
                                    <QuietAction onClick={clearDestination}>Not heading anywhere</QuietAction>
                                </>
                            ) : headingSomewhere ? (
                                <>
                                    <p className="text-body-card text-body">Where are you headed?</p>
                                    <PlaceSearch
                                        onChoose={chooseDestination}
                                        placeholder="Search for where you're going"
                                        label="Search for your destination"
                                    />
                                    <QuietAction onClick={() => setHeadingSomewhere(false)}>Never mind</QuietAction>
                                </>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setHeadingSomewhere(true)}
                                    className="text-ink text-xs font-semibold underline underline-offset-[3px]"
                                >
                                    Heading somewhere?
                                </button>
                            )}
                        </div>

                        <div className="space-y-4">
                            <PrimaryPill type="submit" disabled={processing || data.origin === null}>
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

interface OriginFieldProps {
    state: LocationState;
    originLabel: string | null;
    onUseMyLocation: () => void;
    onChoosePlace: (place: PlaceSuggestion) => void;
    onStartOver: () => void;
}

function OriginField({ state, originLabel, onUseMyLocation, onChoosePlace, onStartOver }: OriginFieldProps) {
    if (originLabel !== null) {
        return (
            <div className="space-y-2">
                <p className="text-body-card text-body">
                    Starting from <span className="text-ink font-semibold">{originLabel}</span>.
                </p>
                <button type="button" onClick={onStartOver} className="text-ink text-xs font-semibold underline underline-offset-[3px]">
                    Somewhere else
                </button>
            </div>
        );
    }

    if (state === 'manual') {
        return (
            <div className="space-y-3">
                <p className="text-body-card text-body">Tell me where you're starting from and I'll take it from there.</p>
                <PlaceSearch onChoose={onChoosePlace} label="Search for your starting point" />
                {/* One quiet affordance remains — no nagging (SCREENS S2). */}
                <button type="button" onClick={onUseMyLocation} className="text-ink text-xs font-semibold underline underline-offset-[3px]">
                    Use my location
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <button
                type="button"
                onClick={onUseMyLocation}
                className="text-ink text-xs font-semibold underline underline-offset-[3px]"
                disabled={state === 'locating'}
            >
                {state === 'locating' ? 'Finding you…' : 'Use my location'}
            </button>
        </div>
    );
}
