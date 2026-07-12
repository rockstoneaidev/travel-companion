import { AppHeader, ChoicePill, EditorialLede, PrimaryPill } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { type TravelMode } from '@/types/enums';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

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

interface PlaceSuggestion {
    id: string;
    name: string;
    location: { lat: number; lng: number };
    type: string;
}

const TIME_CHIPS = [
    { label: '45 min', minutes: 45 },
    { label: '2 h', minutes: 120 },
    { label: '3 h', minutes: 180 },
    { label: 'All day', minutes: 480 },
];

type LocationState = 'idle' | 'locating' | 'located' | 'manual';

export default function ExploreIndex({ travelModeOptions }: ExploreIndexProps) {
    const [state, setState] = useState<LocationState>('idle');
    const [originLabel, setOriginLabel] = useState<string | null>(null);

    const { data, setData, post, processing } = useForm({
        origin: null as { lat: number; lng: number } | null,
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

    const choosePlace = (place: PlaceSuggestion) => {
        setData('origin', place.location);
        setOriginLabel(place.name);
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
                            onChoosePlace={choosePlace}
                            onStartOver={() => {
                                setData('origin', null);
                                setOriginLabel(null);
                                setState('manual');
                            }}
                        />

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
                <PlaceSearch onChoose={onChoosePlace} />
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

function PlaceSearch({ onChoose }: { onChoose: (place: PlaceSuggestion) => void }) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<PlaceSuggestion[]>([]);
    const [searching, setSearching] = useState(false);
    const latest = useRef(0);

    useEffect(() => {
        const query = term.trim();

        if (query.length < 2) {
            setResults([]);

            return;
        }

        // Debounced: a typeahead must not fire a query per keystroke.
        const handle = window.setTimeout(async () => {
            const ticket = ++latest.current;
            setSearching(true);

            try {
                const response = await fetch(`/places/search?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                });
                const body = await response.json();

                // Ignore a slow response that a newer keystroke has superseded.
                if (ticket === latest.current) {
                    setResults(response.ok ? (body.data ?? []) : []);
                }
            } catch {
                if (ticket === latest.current) {
                    setResults([]);
                }
            } finally {
                if (ticket === latest.current) {
                    setSearching(false);
                }
            }
        }, 250);

        return () => window.clearTimeout(handle);
    }, [term]);

    return (
        <div className="space-y-2">
            <input
                type="search"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                placeholder="Search for a place nearby"
                autoComplete="off"
                aria-label="Search for your starting point"
                className="border-rule text-ink placeholder:text-quiet focus-visible:border-ink w-full border-b bg-transparent py-2 text-base outline-none"
            />

            {searching && results.length === 0 && <p className="text-meta-row text-quiet">Looking…</p>}

            {results.length > 0 && (
                <ul className="divide-rule divide-y">
                    {results.map((place) => (
                        <li key={place.id}>
                            <button
                                type="button"
                                onClick={() => onChoose(place)}
                                className="flex w-full items-baseline justify-between gap-3 py-2 text-left"
                            >
                                <span className="text-body-card text-ink">{place.name}</span>
                                <span className="text-meta-row text-quiet shrink-0">{place.type.replace(/_/g, ' ')}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
