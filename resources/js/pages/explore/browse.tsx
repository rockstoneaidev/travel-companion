import { SectionLabel, Thumb } from '@/components/app';
import type { ThumbImage } from '@/components/app/thumb';
import ProductLayout from '@/layouts/product-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * "Show me everything." (E51.)
 *
 * The feed shows five, because five is the INTERRUPTION budget — how many things are worth
 * putting in front of someone who did not ask. This screen is the other question: *what is
 * actually around me?* Those are different questions, and answering both with "five" makes
 * the product an authority it has not earned.
 *
 * So: the whole candidate set, ranked, with the score and the reason shown. We sort it. The
 * user judges it. That division of labour is the entire point of the screen.
 *
 * It is a LIST, not a deck of cards, and that is deliberate. A card says *"consider this"*;
 * a row says *"here is one of many"*. Rendering ninety-nine of these as full cards would be
 * the feed's voice applied to a context that is explicitly not the feed — shouting the same
 * sentence a hundred times.
 */

interface BrowseItem {
    place_id: string;
    name: string;
    type: string | null;
    type_domain: string | null;
    travel_minutes: number;
    score: number;
    why: string;
    image: ThumbImage | null;
    lat: number;
    lng: number;
}

interface BrowseProps {
    session: { id: string; travel_mode: string };
    items: BrowseItem[];
    total: number;
    limit: number;
}

const VERB: Record<string, string> = { walk: 'walk', bike: 'ride', drive: 'drive' };

export default function Browse({ session, items, total, limit }: BrowseProps) {
    const [opening, setOpening] = useState<string | null>(null);

    const open = (placeId: string) => {
        setOpening(placeId);

        // Opening is where the money is spent: the server mints the opportunity and the
        // recommendation now, so the trace, "why did I get this", keep and dismiss all work
        // exactly as they do from the feed. Scrolling past ninety-nine of these costs nothing.
        router.post(`/explore/${session.id}/browse/${placeId}`, {}, { onFinish: () => setOpening(null) });
    };

    const more = () => router.reload({ data: { limit: limit + 50 }, only: ['items', 'limit', 'total'] });

    return (
        <ProductLayout>
            <Head title="Everything around you" />

            <div className="bg-paper min-h-full flex-1 px-6 py-8">
                <header className="mb-8">
                    <h1 className="text-headline text-ink font-serif font-medium italic">Everything around you</h1>
                    <p className="text-body-detail text-body mt-2 max-w-md">
                        {total === 0
                            ? 'Nothing within reach of here yet.'
                            : `${total} ${total === 1 ? 'place' : 'places'} you could ${VERB[session.travel_mode] ?? 'reach'} to, best first. My ranking, your call.`}
                    </p>
                </header>

                <ul className="divide-border-soft divide-y">
                    {items.map((item, index) => (
                        <li key={item.place_id}>
                            <button
                                type="button"
                                onClick={() => open(item.place_id)}
                                disabled={opening !== null}
                                className="hover:bg-paper-raised flex w-full items-center gap-4 py-4 text-left transition-colors disabled:opacity-50"
                            >
                                {/* The rank, stated plainly. If we are going to sort a hundred things
                                    for somebody, the least we can do is show them where each one landed. */}
                                <span className="text-quiet w-6 shrink-0 text-right font-mono text-xs tabular-nums">{index + 1}</span>

                                <Thumb image={item.image} />

                                <div className="min-w-0 flex-1">
                                    <p className="text-ink truncate font-medium">{item.name}</p>
                                    <p className="text-quiet text-caption mt-1 truncate">
                                        {item.why} · {item.travel_minutes} min {VERB[session.travel_mode] ?? ''}
                                    </p>
                                </div>

                                {/* The score itself. A ranked list that will not say what it ranked on is
                                    just a more elaborate way of saying "trust me". */}
                                <span className="text-quiet shrink-0 font-mono text-xs tabular-nums">{item.score.toFixed(2)}</span>
                            </button>
                        </li>
                    ))}
                </ul>

                {items.length < total && (
                    <div className="mt-8 flex justify-center">
                        <button
                            type="button"
                            onClick={more}
                            className="border-border-strong text-body hover:bg-paper-raised rounded-full border px-6 py-2 text-sm transition-colors"
                        >
                            Show more ({total - items.length} left)
                        </button>
                    </div>
                )}

                {items.length === 0 && (
                    <SectionLabel className="mt-12 text-center">Nothing reachable within your time budget</SectionLabel>
                )}
            </div>
        </ProductLayout>
    );
}
