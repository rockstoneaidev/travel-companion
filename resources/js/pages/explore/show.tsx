import { EmptyFeed, OpportunityCard, PassoAppHeader, QuietAction, TabBar } from '@/components/passo';
import { type ExploreSession, type SessionOpportunity } from '@/types/travel';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * S1 — NOW, the feed (SCREENS.md): server order is the order, at most one
 * urgent slot, silence as a designed state, and the ~5 s dismiss undo —
 * the POST is deferred until the snackbar expires.
 */

interface ExploreShowProps {
    session: { data: ExploreSession };
    opportunities: { data: SessionOpportunity[] };
}

const TABS = (sessionId: string, tripId: string) => [
    { label: 'Now', href: `/explore/${sessionId}`, active: true },
    { label: 'Trip', href: `/trips/${tripId}` },
];

export default function ExploreShow({ session, opportunities }: ExploreShowProps) {
    const exploreSession = session.data;
    const [dismissing, setDismissing] = useState<string | null>(null);
    const [hidden, setHidden] = useState<string[]>([]);
    const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => clearTimeout(undoTimer.current ?? undefined), []);

    const feedback = (recommendationId: string | null, event: string, metadata: Record<string, string | number | boolean> = {}) => {
        if (recommendationId === null) return;
        router.post(`/recommendations/${recommendationId}/feedback`, { event, metadata }, { preserveScroll: true, preserveState: true });
    };

    const takeMe = (item: SessionOpportunity) => {
        feedback(item.recommendation_id, 'accepted', { started_navigation: true });
        window.open(
            `https://www.google.com/maps/dir/?api=1&destination=${item.place.location.lat},${item.place.location.lng}&travelmode=walking`,
            '_blank',
        );
    };

    // Not for me: hide immediately, POST only when the undo window closes.
    const notForMe = (item: SessionOpportunity) => {
        if (item.recommendation_id === null) return;
        setDismissing(item.recommendation_id);
        setHidden((h) => [...h, item.id]);
        undoTimer.current = setTimeout(() => {
            feedback(item.recommendation_id, 'dismissed');
            setDismissing(null);
        }, 5000);
    };

    const undo = (item: SessionOpportunity) => {
        clearTimeout(undoTimer.current ?? undefined);
        setDismissing(null);
        setHidden((h) => h.filter((id) => id !== item.id));
    };

    const items = opportunities.data.filter((item) => !hidden.includes(item.id));
    const budget =
        exploreSession.time_budget_minutes >= 60
            ? `${Math.round(exploreSession.time_budget_minutes / 60)} h`
            : `${exploreSession.time_budget_minutes} min`;

    return (
        <div className="bg-paper min-h-screen pb-28 lg:pl-40">
            <Head title="Now" />
            <TabBar tabs={TABS(exploreSession.id, exploreSession.trip_id)} />

            <div className="mx-auto max-w-md space-y-6 px-5 py-8">
                <PassoAppHeader contextStamp={`Stockholm · ${budget} ${exploreSession.travel_mode}`} />

                {items.length === 0 ? (
                    <EmptyFeed
                        headline="Nothing worth interrupting you for."
                        body="You're in a good spot — I'm watching the places around you and I'll have something when it's worth your time."
                    />
                ) : (
                    <>
                        <div className="space-y-4">
                            {items.map((item) => (
                                <div key={item.id}>
                                    <div
                                        role="button"
                                        tabIndex={0}
                                        className="block w-full cursor-pointer text-left"
                                        onClick={() => router.visit(`/opportunities/${item.id}`)}
                                        onKeyDown={(e) => e.key === 'Enter' && router.visit(`/opportunities/${item.id}`)}
                                    >
                                        <OpportunityCard
                                            title={item.title ?? item.place.name}
                                            summary={
                                                item.summary ??
                                                `${item.walk_minutes !== null ? Math.round(item.walk_minutes) : '–'} minutes away on foot.`
                                            }
                                            facets={item.place.facets}
                                            meta={`${item.walk_minutes !== null ? Math.round(item.walk_minutes) : '–'} min walk`}
                                            onTakeMe={() => takeMe(item)}
                                            onKeep={() => feedback(item.recommendation_id, 'saved')}
                                        />
                                    </div>
                                    <div className="mt-1 flex justify-end">
                                        <QuietAction onClick={() => notForMe(item)}>Not for me</QuietAction>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <p className="text-quiet text-center font-serif text-xs italic">That's all for now.</p>
                    </>
                )}

                {exploreSession.status === 'active' && (
                    <div className="border-border-soft border-t pt-4 text-center">
                        <QuietAction onClick={() => router.post(`/explore/${exploreSession.id}/end`)}>End session</QuietAction>
                    </div>
                )}
            </div>

            {dismissing !== null && (
                <div className="bg-ink text-card fixed bottom-24 left-1/2 z-50 flex -translate-x-1/2 items-center gap-4 rounded-full px-5 py-2.5 text-xs shadow-lg">
                    Okay — fewer like this.
                    <button
                        className="font-bold underline underline-offset-[3px]"
                        onClick={() => {
                            const item = opportunities.data.find((i) => i.recommendation_id === dismissing);
                            if (item) undo(item);
                        }}
                    >
                        Undo
                    </button>
                </div>
            )}
        </div>
    );
}
