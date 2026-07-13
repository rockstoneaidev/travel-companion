import { AppHeader, EmptyFeed, QuietAction, SectionLabel, StalenessLine, TextAction, Thumb } from '@/components/app';
import { useOnline } from '@/hooks/use-online';
import ProductLayout from '@/layouts/product-layout';
import { sendFeedback } from '@/lib/feedback';
import { cn } from '@/lib/utils';
import { type DismissedItem, type KeptItem } from '@/types/travel';
import { Head, router } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * S6 — KEPT (SCREENS.md).
 *
 * Two groups, and the split is the whole point: what you can still do, and what
 * has quietly passed. The server decides which is which by re-checking the window
 * against the world model — a keep does not get to claim it is still live just
 * because it was live when you made it.
 */

interface KeptProps {
    kept: { data: KeptItem[] };
    dismissed: { data: DismissedItem[] };
}

export default function Kept({ kept, dismissed }: KeptProps) {
    // KEPT is the screen you actually need in a dead zone — it's your list (S11).
    // It works offline because the service worker serves the last good copy of it.
    const { online, lastFreshAt } = useOnline('kept');
    const [removed, setRemoved] = useState<string[]>([]);
    const [restored, setRestored] = useState<string[]>([]);
    const [showDismissed, setShowDismissed] = useState(false);

    // Windows close while you are looking at the list. Re-ask on refocus so "Take
    // me" is never offered on something that passed while the tab sat in the
    // background — the check is cheap and the alternative is a lie. Pointless
    // offline, though, and S11 forbids retry-hammering.
    useEffect(() => {
        const revalidate = () => navigator.onLine && router.reload({ only: ['kept', 'dismissed'] });
        window.addEventListener('focus', revalidate);

        return () => window.removeEventListener('focus', revalidate);
    }, []);

    const items = kept.data.filter((item) => !removed.includes(item.recommendation_id));
    const stillPossible = items.filter((item) => item.still_possible);
    const passed = items.filter((item) => !item.still_possible);

    const takeMe = (item: KeptItem) => {
        sendFeedback(item.recommendation_id, 'accepted', { started_navigation: true });
    };

    // Removing is housekeeping, not a verdict on the place — it records `unsaved`,
    // which teaches the taste profile nothing (FeedbackEvent::Unsaved).
    const remove = (item: KeptItem) => {
        sendFeedback(item.recommendation_id, 'unsaved');
        setRemoved((ids) => [...ids, item.recommendation_id]); // offline too: the list is the point
    };

    // "Show me these again" — records `undismissed`, which retracts what the dismissal
    // taught the profile (FacetWeightLearner::retract). It puts the item back in the
    // feed; it does not claim the user now likes it.
    const restore = (item: DismissedItem) => {
        sendFeedback(item.recommendation_id, 'undismissed');
        setRestored((ids) => [...ids, item.recommendation_id]);
    };

    const dismissedItems = dismissed.data.filter((item) => !restored.includes(item.recommendation_id));

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Kept" />

                <div className="mx-auto max-w-md space-y-8 px-5 py-8">
                    <AppHeader contextStamp="Kept" />

                    {!online && <StalenessLine lastFreshAt={lastFreshAt} />}

                    {items.length === 0 ? (
                        <EmptyFeed
                            headline="Nothing kept yet."
                            body="When something's worth coming back to, keep it — and it'll be waiting here with its window still checked."
                        />
                    ) : (
                        <>
                            {stillPossible.length > 0 && (
                                <section className="space-y-1">
                                    <SectionLabel>Still possible</SectionLabel>
                                    <div className="divide-border-soft divide-y">
                                        {stillPossible.map((item) => (
                                            <KeptRow key={item.recommendation_id} item={item} onTakeMe={takeMe} onRemove={remove} />
                                        ))}
                                    </div>
                                </section>
                            )}

                            {passed.length > 0 && (
                                <section className="space-y-1">
                                    <SectionLabel>Passed</SectionLabel>
                                    <div className="divide-border-soft divide-y opacity-55">
                                        {passed.map((item) => (
                                            <KeptRow key={item.recommendation_id} item={item} onRemove={remove} />
                                        ))}
                                    </div>
                                </section>
                            )}
                        </>
                    )}

                    {/*
                     * "Not for me", and the way back from it (S6).
                     *
                     * Outside the empty-state branch on purpose: dismissing everything and
                     * keeping nothing is exactly the state in which a user most needs this
                     * list, and hiding it behind "nothing kept yet" would bury the undo at
                     * the one moment it matters.
                     *
                     * Collapsed, quiet, and last. It is a repair tool, not a second feed —
                     * the product's whole argument is that it shows you few things, and a
                     * browsable graveyard of everything you rejected is the catalogue we
                     * refuse to be (PRD §12.1).
                     */}
                    {dismissedItems.length > 0 && (
                        <section className="border-border-soft border-t pt-6">
                            <button
                                type="button"
                                aria-expanded={showDismissed}
                                onClick={() => setShowDismissed((open) => !open)}
                                className="flex min-h-11 w-full items-center justify-between gap-3 text-left"
                            >
                                <SectionLabel>Not for me ({dismissedItems.length})</SectionLabel>
                                <ChevronDown
                                    className={cn('text-meta size-4 transition-transform duration-150', showDismissed && 'rotate-180')}
                                    aria-hidden
                                />
                            </button>

                            {showDismissed && (
                                <>
                                    <p className="text-quiet mt-1 text-xs">
                                        These are hidden from your feed, and I'm showing you fewer like them. Changed your mind?
                                    </p>
                                    <div className="divide-border-soft mt-2 divide-y opacity-70">
                                        {dismissedItems.map((item) => (
                                            <DismissedRow key={item.recommendation_id} item={item} onRestore={restore} />
                                        ))}
                                    </div>
                                </>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </ProductLayout>
    );
}

function KeptRow({ item, onTakeMe, onRemove }: { item: KeptItem; onTakeMe?: (item: KeptItem) => void; onRemove: (item: KeptItem) => void }) {
    const mapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${item.location.lat},${item.location.lng}&travelmode=walking`;

    return (
        <div className="flex gap-3 py-3">
            <Thumb image={item.image} />

            <div className="min-w-0 flex-1">
                <div className="flex items-baseline justify-between gap-3">
                    <h3 className={cn('font-serif text-base font-medium', item.still_possible ? 'text-ink' : 'text-body')}>{item.title}</h3>
                    <span className="text-meta-row text-meta shrink-0 font-medium">{windowLabel(item)}</span>
                </div>

                {item.note !== null && <p className="text-body-card text-body mt-0.5 truncate">{item.note}</p>}

                <div className="mt-1.5 flex items-center gap-6">
                    {onTakeMe !== undefined && (
                        // A real link, so the browser opens maps inside the tap that asked
                        // for it — an async hop first is what popup blockers eat.
                        <a href={mapsUrl} target="_blank" rel="noopener noreferrer" onClick={() => onTakeMe(item)}>
                            <TextAction>Take me</TextAction>
                        </a>
                    )}
                    <QuietAction onClick={() => onRemove(item)}>Remove</QuietAction>
                </div>
            </div>
        </div>
    );
}

/**
 * One dismissed item. No window, no "Take me", no thumbnail-as-invitation — this row
 * exists to be undone, not to be re-sold. The only action is the way back.
 */
function DismissedRow({ item, onRestore }: { item: DismissedItem; onRestore: (item: DismissedItem) => void }) {
    return (
        <div className="flex gap-3 py-3">
            <Thumb image={item.image} />

            <div className="min-w-0 flex-1">
                <h3 className="text-body font-serif text-base font-medium">{item.title}</h3>
                {item.note !== null && <p className="text-body-card text-body mt-0.5 truncate">{item.note}</p>}

                <div className="mt-1.5">
                    <QuietAction onClick={() => onRestore(item)}>Show me these again</QuietAction>
                </div>
            </div>
        </div>
    );
}

/** Right-aligned time (SCREENS S6): what's left of the window, or that it's gone. */
function windowLabel(item: KeptItem): string {
    if (!item.still_possible) return 'window gone';
    if (item.window_ends_at === null) return 'anytime';

    const minutes = Math.round((new Date(item.window_ends_at).getTime() - Date.now()) / 60_000);

    if (minutes < 60) return `${Math.max(1, minutes)} min left`;
    if (minutes < 60 * 24) return `until ~${new Date(item.window_ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;

    return `${Math.round(minutes / (60 * 24))} days left`;
}
