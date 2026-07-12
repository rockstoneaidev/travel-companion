import { AppHeader, ChoicePill, EditorialLede, PlaceSearch, PrimaryPill, QuietAction, SecondaryPill, type PlaceSuggestion } from '@/components/app';
import { useOnline } from '@/hooks/use-online';
import ProductLayout from '@/layouts/product-layout';
import { type TravelMode } from '@/types/enums';
import { Head, Link, useForm } from '@inertiajs/react';
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

/**
 * WHY the browser would not place you. It used to be thrown away — every failure
 * collapsed into `manual` with no explanation — and on iOS that made the button look
 * broken: once a site is denied location, getCurrentPosition errors INSTANTLY, every
 * time, forever. So "Use my location" did fire, could never succeed, and never said
 * why. A control that cannot work must say so; a silent one just looks dead.
 */
type LocationError = 'denied' | 'unavailable' | 'unsupported' | null;

const LOCATION_MESSAGE: Record<Exclude<LocationError, null>, string> = {
    denied: "Your browser is blocking location for this site — it won't ask again until you allow it in the browser's site settings. Search for your starting point instead.",
    unavailable: "I couldn't get a fix on where you are. Try again, or search for your starting point.",
    unsupported: "This browser won't share a location. Search for your starting point instead.",
};

type Point = { lat: number; lng: number };

export default function ExploreIndex({ travelModeOptions }: ExploreIndexProps) {
    const { online } = useOnline('explore-start');
    const [state, setState] = useState<LocationState>('idle');
    const [locationError, setLocationError] = useState<LocationError>(null);
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
            setLocationError('unsupported');
            setState('manual');

            return;
        }

        setLocationError(null);
        setState('locating');

        navigator.geolocation.getCurrentPosition(
            (position) => {
                setData('origin', { lat: position.coords.latitude, lng: position.coords.longitude });
                setOriginLabel('your location');
                setState('located');
            },
            /*
             * Denied and "could not get a fix" are NOT the same thing to the user, and
             * treating them as one is what made this look broken. Denied is permanent
             * until they change a browser setting — retrying will fail instantly and
             * forever — so we have to say that, or they tap a dead-looking control until
             * they give up. A failed fix is worth another try.
             */
            (error) => {
                setLocationError(error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable');
                setState('manual');
            },
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
                            locationError={locationError}
                            originLabel={originLabel}
                            onUseMyLocation={useMyLocation}
                            onChoosePlace={(place) => {
                                setData('origin', place.location);
                                setOriginLabel(place.name);
                            }}
                            onStartOver={() => {
                                setData('origin', null);
                                setOriginLabel(null);
                                setLocationError(null);
                                setState('manual');
                            }}
                        />

                        {/*
                         * Optional destination (SCREENS S2). Collapsed by default —
                         * most sessions are a wander, not a commute. When set, the
                         * session becomes a "route" context and route_fit enters
                         * the composite (SCORING §6).
                         */}
                        {/*
                         * ONLY ONCE THERE IS A START POINT.
                         *
                         * This is how the onboarding actually failed. The traveller searched for
                         * a place, it landed here — in the DESTINATION — and their origin stayed
                         * null, so "Start exploring" was disabled and looked broken. They had
                         * filled in the wrong field and the screen let them.
                         *
                         * A destination without an origin is not just premature, it is
                         * meaningless: route_fit (SCORING §6) is the fit between where you ARE
                         * and where you are going, and it needs both ends. So the second field
                         * does not exist until the first is answered.
                         */}
                        <div className="space-y-2">
                            {data.origin === null ? null : destinationLabel !== null ? (
                                <>
                                    <p className="text-body-card text-body">
                                        Heading to <span className="text-ink font-semibold">{destinationLabel}</span>. I'll look for things on the
                                        way.
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
                            <PrimaryPill type="submit" disabled={processing || data.origin === null || !online}>
                                Start exploring
                            </PrimaryPill>

                            {/*
                             * A greyed-out button is not an explanation. Without an origin the
                             * product is dead — there is no default and there must not be a guess —
                             * so SAY that the starting point is the thing standing in the way,
                             * rather than leaving someone to work it out from a disabled control.
                             */}
                            {data.origin === null && online && (
                                <p className="text-body-card text-meta">I need a starting point before I can look around.</p>
                            )}

                            {/*
                             * Starting a session means scouting, and scouting needs the network.
                             * Say so plainly and point at the thing that DOES work — no spinner,
                             * no retry-hammering a dead zone (S11).
                             */}
                            <EditorialLede>
                                {online ? (
                                    "I'll be quiet until something is worth it."
                                ) : (
                                    <>
                                        I need a connection to look around —{' '}
                                        <Link href="/kept" className="underline underline-offset-[3px]">
                                            Kept
                                        </Link>{' '}
                                        still works.
                                    </>
                                )}
                            </EditorialLede>
                        </div>
                    </form>
                </div>
            </div>
        </ProductLayout>
    );
}

interface OriginFieldProps {
    state: LocationState;
    locationError: LocationError;
    originLabel: string | null;
    onUseMyLocation: () => void;
    onChoosePlace: (place: PlaceSuggestion) => void;
    onStartOver: () => void;
}

function OriginField({ state, locationError, originLabel, onUseMyLocation, onChoosePlace, onStartOver }: OriginFieldProps) {
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
                <p className="text-body-card text-body">Where are you starting from?</p>

                {/*
                 * The REASON, in the user's words. This is the whole fix: every failure used
                 * to collapse into this screen silently, so a denied permission looked
                 * identical to a slow fix — and on iOS a denied site errors instantly and
                 * forever, which made "Use my location" look like a dead button.
                 */}
                {locationError !== null && <p className="text-body-card text-meta">{LOCATION_MESSAGE[locationError]}</p>}

                {/* Retrying a DENIED permission cannot work — it fails instantly, every time.
                    Offering the button again would be inviting them to tap a dead control. */}
                {locationError !== 'denied' && (
                    <SecondaryPill type="button" onClick={onUseMyLocation}>
                        {locationError === null ? 'Use my location' : 'Try my location again'}
                    </SecondaryPill>
                )}

                <PlaceSearch onChoose={onChoosePlace} label="Search for your starting point" />
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {/*
             * The PRIMARY path, and it now looks like one. It used to be 11px underlined text
             * — the same weight as a footnote — for the one action the whole screen depends on.
             */}
            <p className="text-body-card text-body">Where are you starting from?</p>

            <SecondaryPill type="button" onClick={onUseMyLocation} disabled={state === 'locating'}>
                {state === 'locating' ? 'Finding you…' : 'Use my location'}
            </SecondaryPill>

            {/* And the way out, for anyone who would rather not be located at all. */}
            <button type="button" onClick={onStartOver} className="text-ink block text-xs font-semibold underline underline-offset-[3px]">
                Or search for a place
            </button>
        </div>
    );
}
