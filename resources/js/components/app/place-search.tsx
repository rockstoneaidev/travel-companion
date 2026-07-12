import { useEffect, useRef, useState } from 'react';

export interface PlaceSuggestion {
    id: string;
    name: string;
    location: { lat: number; lng: number };
    type: string;
}

interface PlaceSearchProps {
    onChoose: (place: PlaceSuggestion) => void;
    placeholder?: string;
    label?: string;
}

/**
 * Typeahead over our own geo-core (SCREENS S2) — the manual start point, and
 * the optional destination. No geocoder: we search the places we already hold,
 * which keeps it inside the ODbL boundary and off any third-party ToS.
 */
export function PlaceSearch({ onChoose, placeholder = 'Search for a place nearby', label = 'Search for a place' }: PlaceSearchProps) {
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
                placeholder={placeholder}
                autoComplete="off"
                aria-label={label}
                className="border-rule text-ink placeholder:text-quiet focus-visible:border-ink w-full border-b bg-transparent py-2 text-base outline-none"
            />

            {searching && results.length === 0 && <p className="text-meta-row text-quiet">Looking…</p>}

            {results.length > 0 && (
                <ul className="divide-rule divide-y">
                    {results.map((place) => (
                        <li key={place.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChoose(place);
                                    setTerm('');
                                    setResults([]);
                                }}
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
