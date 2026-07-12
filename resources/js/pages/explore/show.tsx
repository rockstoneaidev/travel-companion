import { AppHeader, EmptyFeed, OpportunityCard, QuietAction, TabBar, VisitPromptCard } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { type ExploreSession, type SessionOpportunity, type VisitPrompt } from '@/types/travel';
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
    visitPrompts: { data: VisitPrompt[] };
}

const TABS = (sessionId: string, tripId: string) => [
    { label: 'Now', href: `/explore/${sessionId}`, active: true },
    { label: 'Trip', href: `/trips/${tripId}` },
];

/** Metres between two points — enough precision for a 150 m proximity gate. */
function metresBetween(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
    const R = 6_371_000;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const h =
        Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

const VISIT_PROMPT_RADIUS_M = 150;

export default function ExploreShow({ session, opportunities, visitPrompts }: ExploreShowProps) {
    const exploreSession = session.data;
    const [dismissing, setDismissing] = useState<string | null>(null);
    const [hidden, setHidden] = useState<string[]>([]);
    const [answered, setAnswered] = useState<string[]>([]);
    const [here, setHere] = useState<{ lat: number; lng: number } | null>(null);
    const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => () => clearTimeout(undoTimer.current ?? undefined), []);

    // The proximity half of the "Were you there?" rule (SCREENS S4). Only asked
    // for if there is something to ask about, and never a permission prompt of
    // its own — if we cannot place the user, we simply do not ask.
    useEffect(() => {
        if (visitPrompts.data.length === 0 || !navigator.geolocation) return;

        navigator.geolocation.getCurrentPosition(
            (position) => setHere({ lat: position.coords.latitude, lng: position.coords.longitude }),
            () => setHere(null),
            { timeout: 10_000, maximumAge: 300_000 },
        );
    }, [visitPrompts.data.length]);

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

    const answerVisitPrompt = (prompt: VisitPrompt, wasThere: boolean) => {
        setAnswered((a) => [...a, prompt.recommendation_id]);
        // "Didn't go" is recorded but teaches nothing — the user accepted this
        // item, so it must never be wired to `dismissed` (SCREENS S4).
        feedback(prompt.recommendation_id, wasThere ? 'visited' : 'visit_prompt_declined');
    };

    const items = opportunities.data.filter((item) => !hidden.includes(item.id));

    const prompts = visitPrompts.data.filter((prompt) => {
        if (answered.includes(prompt.recommendation_id)) return false;
        if (here === null) return false; // cannot confirm they are near it — don't ask

        return metresBetween(here, prompt.location) <= VISIT_PROMPT_RADIUS_M;
    });

    const budget =
        exploreSession.time_budget_minutes >= 60
            ? `${Math.round(exploreSession.time_budget_minutes / 60)} h`
            : `${exploreSession.time_budget_minutes} min`;

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1 pb-28">
                <Head title="Now" />
                <TabBar tabs={TABS(exploreSession.id, exploreSession.trip_id)} />

                <div className="mx-auto max-w-md space-y-6 px-5 py-8">
                    <AppHeader contextStamp={`Stockholm · ${budget} ${exploreSession.travel_mode}`} />

                    {/* Top of NOW, above the feed — quiet, dismissible, never a modal. */}
                    {prompts.map((prompt) => (
                        <VisitPromptCard
                            key={prompt.recommendation_id}
                            placeName={prompt.place_name}
                            onWasThere={() => answerVisitPrompt(prompt, true)}
                            onDidntGo={() => answerVisitPrompt(prompt, false)}
                        />
                    ))}

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
                                                urgency={urgencyFor(item)}
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
        </ProductLayout>
    );
}

/**
 * The GO NOW ring (SCREENS S1). `urgent` is the server's decision — at most one
 * per feed, already first in the list — so we only render what it tells us.
 * Ring fraction = remaining / total window.
 */
function urgencyFor(item: SessionOpportunity): { remaining: number; note: string } | undefined {
    if (!item.urgent || item.time_window.ends_at === null) return undefined;

    const now = Date.now();
    const endsAt = new Date(item.time_window.ends_at).getTime();
    const startsAt = item.time_window.starts_at !== null ? new Date(item.time_window.starts_at).getTime() : null;

    const remainingMs = Math.max(0, endsAt - now);
    const totalMs = startsAt !== null ? Math.max(1, endsAt - startsAt) : remainingMs;

    const minutesLeft = Math.round(remainingMs / 60_000);

    return {
        remaining: Math.min(1, remainingMs / totalMs),
        note: minutesLeft >= 60 ? `${Math.round(minutesLeft / 60)} h left` : `${minutesLeft} min left`,
    };
}
