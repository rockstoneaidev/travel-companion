import { AppHeader, EmptyFeed, QuietAction, SectionLabel, Thumb, type ThumbImage } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * S7 — JOURNAL (SCREENS.md).
 *
 * The seed of "your travel memory belongs to you". Phase 1 keeps it thin on
 * purpose: a list, not a scrapbook.
 *
 * It is built from the feedback ledger and not from location history, which is
 * what lets it survive trip-level location deletion (PRD §16). You can erase where
 * you WERE and still keep what you DID — that is the version of the promise that
 * means something.
 */

interface JournalEntry {
    image: ThumbImage | null;
    title: string;
    visited: boolean;
    occurred_at: string;
}

/**
 * The sky while you were there — what we actually observed, not a lookup after the fact
 * (an observation not written down at the time is gone for good).
 *
 * `null` means we never knew, which is NOT the same as "it was dry" — so it renders as
 * nothing at all rather than as a cheerful claim about a day nobody recorded.
 */
interface TripWeather {
    min_c: number | null;
    max_c: number | null;
    wet_observations: number;
    observations: number;
}

interface JournalTrip {
    id: string;
    name: string | null;
    started_at: string;
    weather: TripWeather | null;
    entries: JournalEntry[];
}

/** "14–19°, some rain" — a range, because a mean is true of a week nobody experienced. */
function weatherLine(weather: TripWeather): string | null {
    const parts: string[] = [];

    if (weather.min_c !== null && weather.max_c !== null) {
        parts.push(weather.min_c === weather.max_c ? `${weather.max_c}°` : `${weather.min_c}–${weather.max_c}°`);
    }

    if (weather.wet_observations > 0) {
        parts.push(weather.wet_observations === weather.observations ? 'wet throughout' : 'some rain');
    } else if (weather.observations > 0) {
        parts.push('dry');
    }

    return parts.length > 0 ? parts.join(' · ') : null;
}

export default function Journal({ trips }: { trips: JournalTrip[] }) {
    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Journal" />

                <div className="mx-auto max-w-md space-y-8 px-5 py-8">
                    <AppHeader contextStamp="Journal" />

                    {trips.length === 0 ? (
                        <EmptyFeed
                            headline="Nothing here yet."
                            body="When you go somewhere I suggested, it'll turn up here — a quiet record of where you actually went."
                        />
                    ) : (
                        trips.map((trip) => <TripSection key={trip.id} trip={trip} />)
                    )}
                </div>
            </div>
        </ProductLayout>
    );
}

function TripSection({ trip }: { trip: JournalTrip }) {
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(trip.name ?? '');

    const save = () => {
        router.patch(`/trips/${trip.id}`, { name }, { preserveScroll: true, onSuccess: () => setRenaming(false) });
    };

    const forgetLocations = () => {
        // Erasing where you were does not erase what you did — the entries below are
        // built from the feedback ledger, not from location history (PRD §16).
        router.delete(`/api/v1/trips/${trip.id}/location-history`, { preserveScroll: true });
    };

    return (
        <section className="space-y-2">
            <div className="flex items-baseline justify-between gap-3">
                {renaming ? (
                    <input
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onBlur={save}
                        onKeyDown={(e) => e.key === 'Enter' && save()}
                        className="border-border-strong text-ink w-full border-b bg-transparent font-serif text-lg font-medium focus:outline-none"
                    />
                ) : (
                    <button type="button" onClick={() => setRenaming(true)} className="text-ink text-left font-serif text-lg font-medium">
                        {trip.name ?? 'A trip'}
                    </button>
                )}

                <span className="text-meta-row text-meta shrink-0">
                    {new Date(trip.started_at).toLocaleDateString([], { day: 'numeric', month: 'short' })}
                    {/* Silent when we never knew. An absent observation must not become a claim. */}
                    {trip.weather && weatherLine(trip.weather) && <> · {weatherLine(trip.weather)}</>}
                </span>
            </div>

            {trip.entries.length === 0 ? (
                <p className="text-quiet font-serif text-sm italic">Nothing recorded on this one.</p>
            ) : (
                <div className="divide-border-soft divide-y">
                    {trip.entries.map((entry) => (
                        <div key={entry.title} className="flex items-center gap-3 py-2.5">
                            <Thumb image={entry.image} className="size-12" />

                            <div className="min-w-0 flex-1">
                                <h3 className="text-ink truncate font-serif text-base">{entry.title}</h3>
                                {/* The golden label, shown as the confirmation it is. */}
                                {entry.visited && <SectionLabel className="mt-0.5">I was here</SectionLabel>}
                            </div>
                            <span className="text-meta-row text-meta shrink-0">
                                {new Date(entry.occurred_at).toLocaleDateString([], { day: 'numeric', month: 'short' })}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            <div className="flex justify-end pt-1">
                <QuietAction onClick={forgetLocations}>Delete this trip's location history</QuietAction>
            </div>
        </section>
    );
}
