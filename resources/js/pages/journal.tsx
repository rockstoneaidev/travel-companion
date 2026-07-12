import { AppHeader, EmptyFeed, QuietAction, SectionLabel } from '@/components/app';
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
    title: string;
    visited: boolean;
    occurred_at: string;
}

interface JournalTrip {
    id: string;
    name: string | null;
    started_at: string;
    entries: JournalEntry[];
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
                </span>
            </div>

            {trip.entries.length === 0 ? (
                <p className="text-quiet font-serif text-sm italic">Nothing recorded on this one.</p>
            ) : (
                <div className="divide-border-soft divide-y">
                    {trip.entries.map((entry) => (
                        <div key={entry.title} className="flex items-baseline justify-between gap-3 py-2.5">
                            <div>
                                <h3 className="text-ink font-serif text-base">{entry.title}</h3>
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
