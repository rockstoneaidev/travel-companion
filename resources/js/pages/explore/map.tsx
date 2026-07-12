import { EmptyFeed, PeekSheet, StalenessLine, TabBar } from '@/components/app';
import type { MapItem } from '@/components/app/paper-map';
import { useOnline } from '@/hooks/use-online';
import ProductLayout from '@/layouts/product-layout';
import { sendFeedback } from '@/lib/feedback';
import { type ExploreSession, type SessionOpportunity } from '@/types/travel';
import { Head, router } from '@inertiajs/react';
import { lazy, Suspense, useMemo, useState } from 'react';

/**
 * S3 — MAP (SCREENS.md).
 *
 * The map bundle is ~200KB gzipped, so it is lazy-loaded here and nowhere else:
 * the feed is the critical path and must not pay for a screen the user may never
 * open (DESIGN §3). This import is what makes it a separate Vite chunk — importing
 * PaperMap through the components barrel would defeat it.
 */
const PaperMap = lazy(() => import('@/components/app/paper-map'));

interface ExploreMapProps {
    session: { data: ExploreSession };
    opportunities: { data: SessionOpportunity[] };
}

export default function ExploreMap({ session, opportunities }: ExploreMapProps) {
    const exploreSession = session.data;
    const { online, lastFreshAt } = useOnline('map');
    const [selectedId, setSelectedId] = useState<string | null>(null);

    const items = opportunities.data;
    const selected = items.find((item) => item.id === selectedId) ?? null;

    const tabs = [
        { label: 'Now', href: `/explore/${exploreSession.id}` },
        { label: 'Map', href: `/explore/${exploreSession.id}/map`, active: true },
        { label: 'Trip', href: `/trips/${exploreSession.trip_id}` },
    ];

    // Memoised because selecting a pin re-renders this component: a fresh array here
    // would hand the map a "new" pin set on every tap.
    const pins: MapItem[] = useMemo(
        () =>
            items.map((item) => ({
                id: item.id,
                lat: item.place.location.lat,
                lng: item.place.location.lng,
                // The pin chip is a glance, not a title — the peek sheet carries the real one.
                label: item.place.name,
                urgent: item.urgent,
            })),
        [items],
    );

    const takeMe = (item: SessionOpportunity) => {
        sendFeedback(item.recommendation_id, 'accepted', { started_navigation: true });

        window.open(
            `https://www.google.com/maps/dir/?api=1&destination=${item.place.location.lat},${item.place.location.lng}&travelmode=walking`,
            '_blank',
        );
    };

    const walkTime = (item: SessionOpportunity) => (item.walk_minutes !== null ? `${Math.round(item.walk_minutes)} min walk` : '– min walk');

    return (
        <ProductLayout>
            <Head title="Map" />

            <div className="bg-map-bg relative flex-1">
                {/*
                 * Offline the tiles are gone, and a blank paper rectangle looks like a
                 * broken map rather than a missing network. Say which it is (S11) —
                 * and the pins we already know about still have somewhere to be.
                 */}
                {!online && (
                    <div className="absolute inset-x-0 top-0 z-30 px-3 pt-3">
                        <StalenessLine lastFreshAt={lastFreshAt} className="bg-card/90 rounded-[14px] border-none px-3" />
                    </div>
                )}

                {/* Silence is a designed state here too: an empty map is not an error. */}
                {items.length === 0 && (
                    <div className="absolute inset-0 z-10 grid place-items-center px-8">
                        <EmptyFeed
                            headline="Nothing on the map yet."
                            body="I'm watching the places around you — when something's worth the walk, it'll appear here."
                        />
                    </div>
                )}

                <Suspense
                    fallback={<div className="bg-map-bg text-quiet grid h-full place-items-center font-serif text-sm italic">Unfolding the map…</div>}
                >
                    <PaperMap items={pins} origin={exploreSession.origin} selectedId={selectedId} onSelect={setSelectedId} />
                </Suspense>

                {selected !== null && (
                    <PeekSheet
                        label={selected.urgent ? 'Go now' : (selected.place.facets[0] ?? 'nearby')}
                        meta={walkTime(selected)}
                        title={selected.title ?? selected.place.name}
                        note={selected.summary ?? `${selected.place.name} is a short walk from here.`}
                        urgent={selected.urgent}
                        onTakeMe={() => takeMe(selected)}
                        onOpen={() => router.visit(`/opportunities/${selected.id}`)}
                        onDismiss={() => setSelectedId(null)}
                    />
                )}
            </div>

            <TabBar tabs={tabs} />
        </ProductLayout>
    );
}
