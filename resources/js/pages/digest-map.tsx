import { EmptyFeed, PeekSheet } from '@/components/app';
import type { MapItem } from '@/components/app/paper-map';
import type { ThumbImage } from '@/components/app/thumb';
import ProductLayout from '@/layouts/product-layout';
import { Head, router } from '@inertiajs/react';
import { lazy, Suspense, useMemo, useState } from 'react';

const PaperMap = lazy(() => import('@/components/app/paper-map'));

/**
 * The digest, drawn as geography (S8's "Open map").
 *
 * A SESSION-LESS map, and that is the whole point of it. The footer of the digest has
 * always said "Save any to today's map", and the link under it went to `/map` — which
 * resolves the *active session's* map. Over breakfast there is no active session, so it
 * fell through to the session start form, and the founder reasonably read that as
 * "clicking the map started a new session".
 *
 * Asking somebody to declare "I have three hours" before they may look at a map is the app
 * demanding a commitment in exchange for information. The digest's map is the digest's
 * places, and you may simply look at them.
 */

interface DigestMapItem {
    opportunity_id: string;
    title: string;
    note: string | null;
    window_ends_at: string | null;
    image: ThumbImage | null;
    lat: number;
    lng: number;
}

interface DigestMapProps {
    lede: string;
    items: DigestMapItem[];
}

export default function DigestMap({ lede, items }: DigestMapProps) {
    const [selectedId, setSelectedId] = useState<string | null>(null);

    const pins = useMemo<MapItem[]>(
        () =>
            items.map((item) => ({
                id: item.opportunity_id,
                lat: item.lat,
                lng: item.lng,
                label: item.title,
                // Nothing on the digest map is urgent: the digest is a morning read, not an
                // interruption. Borrowing the GO NOW ring here would spend the one piece of
                // visual urgency the product owns on a screen that is explicitly calm.
                urgent: false,
            })),
        [items],
    );

    const selected = items.find((item) => item.opportunity_id === selectedId) ?? null;

    return (
        <ProductLayout>
            <div className="bg-paper relative min-h-full flex-1">
                <Head title="Today's map" />

                {items.length === 0 ? (
                    <EmptyFeed headline="Nothing on today's map yet." body={lede} />
                ) : (
                    <div className="relative h-[calc(100vh-4rem)]">
                        <Suspense fallback={<div className="text-quiet grid h-full place-items-center font-serif italic">Unfolding the map…</div>}>
                            <PaperMap items={pins} origin={null} selectedId={selectedId} onSelect={setSelectedId} className="h-full w-full" />
                        </Suspense>

                        {selected !== null && (
                            <PeekSheet
                                label="Today"
                                meta={
                                    selected.window_ends_at !== null
                                        ? `until ~${new Date(selected.window_ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`
                                        : 'anytime'
                                }
                                title={selected.title}
                                note={selected.note ?? 'Worth a look today.'}
                                // A pin tells you a place exists; a picture tells you whether you
                                // want to go. This is the screen where you are deciding BETWEEN
                                // places, and it is the screen that showed none of them.
                                image={selected.image}
                                onOpen={() => router.visit(`/opportunities/${selected.opportunity_id}`)}
                                onTakeMe={() =>
                                    window.open(
                                        `https://www.google.com/maps/dir/?api=1&destination=${selected.lat},${selected.lng}&travelmode=walking`,
                                        '_blank',
                                    )
                                }
                                onDismiss={() => setSelectedId(null)}
                            />
                        )}
                    </div>
                )}
            </div>
        </ProductLayout>
    );
}
