import { AppHeader, EditorialLede, PrimaryPill, SectionLabel, TextAction, Thumb, type ThumbImage } from '@/components/app';
import { useExploreHere } from '@/hooks/use-explore-here';
import ProductLayout from '@/layouts/product-layout';
import { Head, Link, router } from '@inertiajs/react';
import { lazy, Suspense, useEffect, useMemo, useState } from 'react';

/**
 * Home — "today".
 *
 * It was the starter kit's empty skeleton, then it was honest and dull: a lede, a
 * button, a count. The obvious fix is "more photographs", and the world model cannot
 * pay for it — 1,479 of 53,133 places carry an image (2.8%), and even among our
 * approved curated items only 25 of 98 do. A photo grid would be three-quarters
 * empty boxes, which is worse than plain text because it looks broken.
 *
 * Every place has a LOCATION, though. Geography is the only picture we can always
 * draw, so the map is this screen's imagery and the photographs are the accent on
 * top of it.
 *
 * The map shows YOU, what you KEPT (solid pins), and what the ranker PASSED OVER
 * (hollow dots — visible, not shouted). It does not show the other 53,000 places we
 * know about: that map is a different product, one that hands the choosing back to
 * the user, which is the single job this one claims to do for them.
 *
 * Evening is the state that looked broken — a near-empty page at 23:15 reading
 * "Nothing needs deciding tonight". The words are right (inventing urgency at
 * midnight would be the product lying), but silence is not the same as a blank page.
 * So evening keeps the map and drops the nudge: where you are, and what is still
 * standing around you. Orientation is not an interruption.
 */

// ~200KB gzipped. Lazy here exactly as on S3, and never through the barrel, or every
// screen pays for a map most of them never draw (DESIGN §3).
const PaperMap = lazy(() => import('@/components/app/paper-map'));

interface DigestCard {
    opportunity_id: string;
    title: string;
    note: string | null;
    image: ThumbImage | null;
}

interface MapPin {
    id: string;
    lat: number;
    lng: number;
    label: string;
    dimmed: boolean;
    href: string | null;
}

interface DashboardProps {
    digest: {
        variant: 'morning' | 'evening';
        lede: string;
        subline: string;
        items: DigestCard[];
    };
    hero: DigestCard | null;
    session: string | null;
    map: { origin: { lat: number; lng: number } | null; pins: MapPin[] };
    kept: { still_possible: number; total: number };
}

export default function Dashboard({ digest, hero, session, map, kept }: DashboardProps) {
    const exploreHere = useExploreHere();
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [here, setHere] = useState<{ lat: number; lng: number } | null>(null);
    const evening = digest.variant === 'evening';

    /*
     * WHERE YOU ACTUALLY ARE — or an honest label saying otherwise.
     *
     * The marker said "you" and pointed at the origin of your LAST session. That is not
     * a rounding error: the map was telling you that you were somewhere you were not.
     *
     * So we ask the browser. Only if permission is ALREADY granted, though — opening the
     * home screen must not throw a location prompt at someone who did not ask to be
     * found (PRD §8: foreground, in context, never on app open). If we cannot have it,
     * we fall back to the last origin and CALL IT WHAT IT IS: "last start", not "you".
     *
     * Nothing is sent to the server. This position only moves a pin on your own screen.
     */
    useEffect(() => {
        if (!navigator.geolocation || !navigator.permissions) return;

        void navigator.permissions
            .query({ name: 'geolocation' })
            .then((status) => {
                if (status.state !== 'granted') return;

                navigator.geolocation.getCurrentPosition(
                    (position) => setHere({ lat: position.coords.latitude, lng: position.coords.longitude }),
                    () => setHere(null),
                    { timeout: 8_000 },
                );
            })
            .catch(() => undefined);
    }, []);

    const origin = here ?? map.origin;

    // A new array on every render would tear down the GL context on each pin tap —
    // the bug S3 already paid for once.
    const pins = useMemo(
        () => map.pins.map((pin) => ({ id: pin.id, lat: pin.lat, lng: pin.lng, label: pin.label, urgent: false, dimmed: pin.dimmed })),
        [map.pins],
    );

    const selected = map.pins.find((pin) => pin.id === selectedId) ?? null;

    // The rest — the hero is already showing the first one.
    const rest = digest.items.filter((item) => item.opportunity_id !== hero?.opportunity_id);

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Today" />

                <div className="mx-auto max-w-md space-y-8 px-5 py-8 lg:max-w-2xl">
                    <AppHeader contextStamp={evening ? 'Tonight' : 'Today'} />

                    <div className="space-y-2">
                        {/* Written from real context, never generated — "it's dry until four"
                            is a factual claim, and the LLM is never a source of facts. */}
                        <h1 className="text-headline text-ink font-serif font-medium italic">{digest.lede}</h1>
                        <p className="text-body-card text-body">{digest.subline}</p>
                    </div>

                    {/* THE HERO. One thing, big, with its picture — not a grid. Scarcity is
                        the product (PRD §12.1); a wall of cards would say "here is
                        everything, you decide", which is the job we took off the user. */}
                    {hero !== null && (
                        <button
                            type="button"
                            onClick={() => router.visit(`/opportunities/${hero.opportunity_id}`)}
                            className="rounded-card border-border shadow-card group relative block w-full overflow-hidden border text-left"
                        >
                            {hero.image !== null ? (
                                <img
                                    src={hero.image.url}
                                    alt=""
                                    className="h-56 w-full object-cover transition-transform duration-500 group-hover:scale-[1.02] lg:h-72"
                                />
                            ) : (
                                <div className="paper-stripe h-56 w-full lg:h-72" />
                            )}

                            {/* The scrim exists so the words stay readable over a photograph we
                                did not art-direct and cannot re-shoot. */}
                            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 via-black/35 to-transparent p-5 pt-16">
                                <h2 className="font-serif text-xl font-medium text-white drop-shadow-sm">{hero.title}</h2>
                                {hero.note !== null && <p className="mt-1 line-clamp-2 text-sm text-white/85">{hero.note}</p>}
                            </div>
                        </button>
                    )}

                    {/* The single most useful thing this screen can say — and it used to say
                        nothing at all: you have a session open, and here is the way back. */}
                    <div className="flex flex-wrap items-center gap-4">
                        {session !== null ? (
                            <PrimaryPill onClick={() => router.visit(`/explore/${session}`)}>Back to what's near you</PrimaryPill>
                        ) : (
                            <>
                                {/* One tap: start a session where you are standing. No form —
                                    it finds you, gathers what's around, and opens the feed. */}
                                <PrimaryPill onClick={exploreHere.go} disabled={exploreHere.locating}>
                                    {exploreHere.locating ? 'Finding you…' : 'Explore my position'}
                                </PrimaryPill>
                                <Link href="/explore">
                                    <TextAction>I have some time</TextAction>
                                </Link>
                            </>
                        )}

                        {kept.total > 0 && (
                            <Link href="/kept">
                                <TextAction>
                                    {kept.still_possible} of {kept.total} kept still possible
                                </TextAction>
                            </Link>
                        )}
                    </div>

                    {exploreHere.denied && <p className="text-quiet text-xs">Turn on location to explore where you are.</p>}

                    {/* THE MAP. Not a widget — the imagery. And not a map of everything: you,
                        what you kept, and the ones the ranker weighed and held back. */}
                    {origin !== null && (
                        <section className="space-y-2">
                            <SectionLabel>Around you</SectionLabel>

                            <div className="rounded-card border-border shadow-card relative h-72 overflow-hidden border lg:h-80">
                                <Suspense fallback={<div className="bg-map-bg h-full w-full" />}>
                                    <PaperMap
                                        items={pins}
                                        origin={origin}
                                        // "you" is a claim. Only make it when it is true.
                                        originLabel={here !== null ? 'you' : 'last start'}
                                        selectedId={selectedId}
                                        onSelect={setSelectedId}
                                    />
                                </Suspense>

                                {/* Tapping a pin should go somewhere. A map you can only look at
                                    is a picture of a product. */}
                                {selected !== null && selected.href !== null && (
                                    <button
                                        type="button"
                                        onClick={() => router.visit(selected.href!)}
                                        className="rounded-card border-border bg-card shadow-card absolute inset-x-3 bottom-3 border p-3 text-left"
                                    >
                                        <span className="text-ink block font-serif text-base font-medium">{selected.label}</span>
                                        <span className="text-body-card text-meta">Open</span>
                                    </button>
                                )}
                            </div>

                            {/* A legend, so it is written as one. The old line — "You, what you
                                kept, and — hollow — what I weighed and passed over" — wedged the
                                pin STYLE into the middle of a clause, so "hollow" had no noun to
                                attach to and the reader had to work out that it described the
                                drawing rather than the places. Two short sentences: what you see,
                                then what it means. */}
                            <p className="text-body-card text-meta">
                                You, and the places you kept. The empty circles are ones I considered but didn&rsquo;t suggest.
                            </p>
                        </section>
                    )}

                    {rest.length > 0 && (
                        <section className="space-y-2">
                            <SectionLabel>{evening ? 'What I passed over' : 'Also worth knowing'}</SectionLabel>

                            <div className="rounded-card border-border bg-card divide-border-soft shadow-card divide-y border">
                                {rest.map((item) => (
                                    <button
                                        key={item.opportunity_id}
                                        type="button"
                                        className="flex w-full gap-3 p-4 text-left"
                                        onClick={() => router.visit(`/opportunities/${item.opportunity_id}`)}
                                    >
                                        <Thumb image={item.image} />

                                        <span className="min-w-0 flex-1">
                                            <h3 className="text-ink font-serif text-base font-medium">{item.title}</h3>
                                            {item.note !== null && (
                                                <span className="text-body-card text-body mt-0.5 block truncate">{item.note}</span>
                                            )}
                                        </span>
                                    </button>
                                ))}
                            </div>

                            <div className="flex justify-end">
                                <Link href="/digest/today">
                                    <TextAction>The whole digest</TextAction>
                                </Link>
                            </div>
                        </section>
                    )}

                    <EditorialLede>I'll be quiet until something is worth it.</EditorialLede>
                </div>
            </div>
        </ProductLayout>
    );
}
