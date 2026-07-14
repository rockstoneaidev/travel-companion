import { AppHeader, EmptyFeed, OpportunityCard, QuietAction, StalenessLine, TabBar, VisitPromptCard } from '@/components/app';
import { useLivingFeed } from '@/hooks/use-living-feed';
import { useOnline } from '@/hooks/use-online';
import ProductLayout from '@/layouts/product-layout';
import { sendFeedback } from '@/lib/feedback';
import { travelMeta, travelSummary } from '@/lib/travel-time';
import { cn } from '@/lib/utils';
import { type ExploreSession, type ServeMeta, type SessionOpportunity, type VisitPrompt } from '@/types/travel';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * S1 — NOW, the feed (SCREENS.md): server order is the order, at most one
 * urgent slot, silence as a designed state, and the ~5 s dismiss undo —
 * the POST is deferred until the snackbar expires.
 *
 * The feed is alive (E46): opening the screen reports where you are, and the server
 * re-anchors the menu if you have walked somewhere new. Nothing here re-ranks or
 * re-sorts — the client's entire role in that loop is to say "here I am" and ask
 * again. Server order is still the order.
 */

interface ExploreShowProps {
    session: { data: ExploreSession };
    opportunities: { data: SessionOpportunity[] };
    visitPrompts: { data: VisitPrompt[] };
    serve: ServeMeta | null;
    /**
     * An empty feed means three different things (E48), and we used to tell one story:
     * we looked and it's quiet · we've never heard of here and are learning it now ·
     * we've never heard of here and nothing is coming.
     */
    coverage: {
        known: boolean;
        learning: boolean;
        region?: string | null;
        progress: { done: number; total: number; failed: number } | null;
    };
}

const TABS = (sessionId: string, tripId: string) => [
    { label: 'Now', href: `/explore/${sessionId}`, active: true },
    { label: 'Map', href: `/explore/${sessionId}/map` },
    { label: 'Trip', href: `/trips/${tripId}` },
];

/** Metres between two points — enough precision for a 150 m proximity gate. */
function metresBetween(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
    const R = 6_371_000;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

const VISIT_PROMPT_RADIUS_M = 150;

export default function ExploreShow({ session, opportunities, visitPrompts, serve, coverage }: ExploreShowProps) {
    const exploreSession = session.data;
    const { online, lastFreshAt } = useOnline('feed');
    const [dismissing, setDismissing] = useState<string | null>(null);
    const [hidden, setHidden] = useState<string[]>([]);
    // Seeded from the ledger, not empty: a kept card must still say "Kept" after a
    // reload. Keyed on the opportunity id, like `hidden`.
    const [kept, setKept] = useState<string[]>(() => opportunities.data.filter((item) => item.kept).map((item) => item.id));
    const [answered, setAnswered] = useState<string[]>([]);
    const [here, setHere] = useState<{ lat: number; lng: number } | null>(null);

    /*
     * One timer PER dismissal, not one timer for the screen.
     *
     * This was a single ref, and it quietly ate feedback: dismiss two cards inside the
     * 5 s undo window and the second `setTimeout` overwrote the first, so the first
     * card's `dismissed` POST never fired — while the card stayed hidden, because
     * `hidden` kept both. The user saw two dismissals and the ledger got one, and at
     * η .25 that is the strongest signal the Phase 1 learner has. It also means the
     * lost place is not excluded from the next batch (E46), so it walks back into the
     * feed — which is precisely the bug this epic is here to kill.
     */
    const undoTimers = useRef(new Map<string, ReturnType<typeof setTimeout>>());

    useEffect(() => {
        const timers = undoTimers.current;

        return () => timers.forEach(clearTimeout);
    }, []);

    /*
     * Report where we are, and re-pull if the server re-anchored (E46). Only while the
     * session is live: an ended session is a record, and the server will not re-serve it.
     *
     * `context_source` is load-bearing, not decoration. An EMULATED session is driven by
     * a pin in /admin/emulator, and this screen renders inside that console's iframe — so
     * without this argument the hook reads the OPERATOR'S REAL GEOLOCATION and posts it
     * into the simulation. It did: a walk across Vasastaden re-anchored onto Liljeholmen,
     * because that is where the founder's body was (E47, 2026-07-14).
     */
    const { refresh } = useLivingFeed(exploreSession.id, exploreSession.status === 'active', exploreSession.context_source, coverage.learning);

    /*
     * "You've moved" — shown only when the server actually replaced the menu.
     *
     * Detected from the serve group climbing, not from the browser's own sense of
     * having moved: the client does not get to decide that it is somewhere new. It
     * reports a position; the server applies the drift threshold and the accuracy
     * discount and either re-anchors or doesn't. A banner driven off the client's
     * geolocation would announce a fresh menu on the strength of a GPS twitch, and
     * then show the same cards.
     */
    const [moved, setMoved] = useState(false);
    const lastGroup = useRef(serve?.group ?? 0);

    useEffect(() => {
        const group = serve?.group ?? 0;

        if (group > lastGroup.current && serve?.reason === 'move_reanchor') {
            setMoved(true);
        }

        lastGroup.current = group;
    }, [serve?.group, serve?.reason]);

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

    // Queued to disk first, sent second (S11) — a dead zone must not cost a tap.
    const feedback = (recommendationId: string | null, event: string, metadata: Record<string, string | number | boolean> = {}) => {
        sendFeedback(recommendationId, event, metadata);
    };

    const takeMe = (item: SessionOpportunity) => {
        feedback(item.recommendation_id, 'accepted', { started_navigation: true });
        window.open(
            `https://www.google.com/maps/dir/?api=1&destination=${item.place.location.lat},${item.place.location.lng}&travelmode=walking`,
            '_blank',
        );
    };

    // Keep toggles, and it SHOWS that it toggled. The retraction is `unsaved`, which
    // teaches the profile nothing (FeedbackEvent::Unsaved) — un-keeping is housekeeping,
    // not "fewer like this". Local state only: the card's job is to confirm the tap, and
    // /kept is the screen that owns the list.
    const toggleKeep = (item: SessionOpportunity) => {
        const isKept = kept.includes(item.id);

        feedback(item.recommendation_id, isKept ? 'unsaved' : 'saved');
        setKept((ids) => (isKept ? ids.filter((id) => id !== item.id) : [...ids, item.id]));
    };

    /*
     * Not for me: hide immediately, POST only when the undo window closes.
     *
     * Once that POST lands, the feed reloads (lib/feedback.ts flushes, then reloads),
     * and the server tops the batch back up to a full menu — so a new card slides into
     * the gap rather than the feed simply getting shorter (E46). The client does not
     * request the replacement and does not know what it will be; it just asks again.
     */
    const notForMe = (item: SessionOpportunity) => {
        const recommendationId = item.recommendation_id;

        if (recommendationId === null) return;

        setDismissing(recommendationId);
        setHidden((h) => [...h, item.id]);

        undoTimers.current.set(
            recommendationId,
            setTimeout(() => {
                feedback(recommendationId, 'dismissed');
                undoTimers.current.delete(recommendationId);
                setDismissing((current) => (current === recommendationId ? null : current));
            }, 5000),
        );
    };

    const undo = (item: SessionOpportunity) => {
        const recommendationId = item.recommendation_id;

        if (recommendationId === null) return;

        clearTimeout(undoTimers.current.get(recommendationId));
        undoTimers.current.delete(recommendationId);
        setDismissing((current) => (current === recommendationId ? null : current));
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

                <div className="mx-auto max-w-md space-y-6 px-5 py-8 lg:max-w-4xl">
                    <AppHeader contextStamp={`Stockholm · ${budget} ${exploreSession.travel_mode}`} />

                    {!online && <StalenessLine lastFreshAt={lastFreshAt} />}

                    {/*
                     * The feed followed you. Say so once, quietly, and get out of the way —
                     * the cards below already ARE the new picks, so this is a note, not an
                     * offer, and it must never be a modal standing between the user and the
                     * thing they opened the app to see.
                     */}
                    {moved && (
                        <div className="border-border-soft text-quiet flex items-center justify-between gap-4 border-l-2 py-1 pl-3 font-serif text-xs italic">
                            <span>You've moved — these are picks for where you are now.</span>
                            <button className="underline underline-offset-[3px]" onClick={() => setMoved(false)}>
                                Got it
                            </button>
                        </div>
                    )}

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
                        /*
                         * TWO DIFFERENT SILENCES, and we were telling the same story about both.
                         *
                         * "Nothing worth interrupting you for. I'm watching the places around
                         * you" is a lovely line when we HAVE swept the neighbourhood and it is
                         * genuinely quiet. It is a lie 700 km north of the launch region, where
                         * we know nothing at all — the founder dropped a pin in Skellefteå, a
                         * town of 35,000, and the app claimed to be keeping an eye on it.
                         *
                         * PRD §8.1 asks for exactly the opposite there: "graceful degradation
                         * elsewhere — we don't know this area deeply yet". Coverage honesty
                         * (§15.3) is not a nicety; the promise this product makes is that when
                         * it says nothing, nothing was worth saying. That promise is worthless
                         * if it also says nothing when it simply wasn't looking.
                         */
                        coverage.known ? (
                            <EmptyFeed
                                headline="Nothing worth interrupting you for."
                                body="You're in a good spot — I'm watching the places around you and I'll have something when it's worth your time."
                            />
                        ) : coverage.learning ? (
                            <EmptyFeed
                                headline={`I'm learning ${coverage.region ?? 'this area'}.`}
                                body={
                                    /*
                                     * HONEST about the clock, because it is not fast.
                                     *
                                     * The first draft promised "a minute or two". Driving it
                                     * for real: public Overpass rate-limits us to 45-second
                                     * waits, and a region is ~55 boxes on one worker — the
                                     * better part of two hours. Places do appear from the
                                     * nearest boxes first (that is what the ordering and the
                                     * progressive resolve are for), so the feed fills in as
                                     * it goes rather than staying dark until the end. But a
                                     * screen that says "a minute or two" and then sits there
                                     * for forty is a screen that lied.
                                     */
                                    coverage.progress !== null
                                        ? `Nobody has been here before, so I'm mapping it — ${coverage.progress.done} of ${coverage.progress.total} areas, starting with the ground you're standing on. Places will appear here as they land. It takes a while; you don't have to wait for it.`
                                        : "Nobody has been here before, so I'm mapping it now, starting with the ground you're standing on. Places will appear here as they land."
                                }
                            />
                        ) : (
                            <EmptyFeed headline="I don't know this area yet." body="I'd rather say so than pretend I'm watching." />
                        )
                    ) : (
                        <>
                            {/*
                             * Still 3–5 items. Scarcity IS the product (PRD §12.1) — this is a
                             * menu, not a catalogue, and a wall of image cards is the aesthetic
                             * of every other travel app and precisely what this one is defined
                             * against.
                             *
                             * But a single narrow column stranded on a 2000px desktop reads as
                             * unfinished. So on a wide screen the same few cards breathe into
                             * two columns, with the urgent one spanning as a hero — server order
                             * is still the order, because reading order is still the order.
                             */}
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {items.map((item) => (
                                    <div key={item.id} className={cn('flex flex-col', urgencyFor(item) !== undefined && 'lg:col-span-2')}>
                                        <div
                                            role="button"
                                            tabIndex={0}
                                            className="block h-full w-full cursor-pointer text-left"
                                            onClick={() => router.visit(`/opportunities/${item.id}`)}
                                            onKeyDown={(e) => e.key === 'Enter' && router.visit(`/opportunities/${item.id}`)}
                                        >
                                            <OpportunityCard
                                                className="h-full"
                                                image={item.image}
                                                title={item.title ?? item.place.name}
                                                summary={item.summary ?? travelSummary(item.travel_minutes, item.travel_mode)}
                                                facets={item.place.facets}
                                                meta={travelMeta(item.travel_minutes, item.travel_mode)}
                                                // A stale GO NOW must not shout (S11). Offline we cannot know
                                                // whether the window is still open, and a countdown we can't
                                                // verify is worse than no countdown: it's a confident lie.
                                                // The card stays, the urgency doesn't.
                                                urgency={online ? urgencyFor(item) : undefined}
                                                onTakeMe={() => takeMe(item)}
                                                onKeep={() => toggleKeep(item)}
                                                kept={kept.includes(item.id)}
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
                        <div className="border-border-soft flex items-center justify-center gap-6 border-t pt-4 text-center">
                            {/*
                             * The user's own override of the drift threshold (E46). They may not
                             * have moved a metre — they may simply have eaten, or read all five
                             * and wanted the next five. A distance threshold cannot know that;
                             * they can.
                             */}
                            <QuietAction onClick={refresh}>Fresh picks from here</QuietAction>
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
