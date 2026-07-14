import { AppHeader, EditorialLede, EmptyFeed, SectionLabel, TextAction, Thumb, type ThumbImage } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { Head, Link, router } from '@inertiajs/react';

/**
 * S8 — the daily digest (SCREENS.md, PRD §12.4).
 *
 * The release valve. These are the things the feed passed over — scored, reachable,
 * and beaten by four better ones. Surfacing them lowers the pressure on every
 * individual interrupt decision, which is the only reason the feed gets to stay as
 * quiet as it does.
 *
 * A screen you FIND. There is no push in Phase 1, and this is not a nag.
 */

interface DigestItem {
    lat: number | null;
    lng: number | null;
    image: ThumbImage | null;
    opportunity_id: string;
    title: string;
    note: string | null;
    window_ends_at: string | null;
}

interface DigestProps {
    digest: {
        variant: 'morning' | 'evening';
        lede: string;
        subline: string;
        trip_id: string | null;
        trip_name: string | null;
        visited_today: number;
        kept_today: number;
        items: DigestItem[];
    };
}

export default function Digest({ digest }: DigestProps) {
    const evening = digest.variant === 'evening';

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title={evening ? 'Tonight' : 'This morning'} />

                <div className="mx-auto max-w-md space-y-6 px-5 py-8">
                    <AppHeader contextStamp={evening ? 'Recap' : 'Today'} />

                    <div className="space-y-2">
                        {/* Written from real context, never generated: "dry until four" is a
                            factual claim, and the LLM is never a source of facts. */}
                        <h1 className="text-headline text-ink font-serif font-medium italic">{digest.lede}</h1>
                        <p className="text-body-card text-body">{digest.subline}</p>
                    </div>

                    {evening && (digest.visited_today > 0 || digest.kept_today > 0) && (
                        <p className="text-meta-row text-meta">
                            {digest.visited_today > 0 && `${digest.visited_today} visited`}
                            {digest.visited_today > 0 && digest.kept_today > 0 && ' · '}
                            {digest.kept_today > 0 && `${digest.kept_today} kept for later`}
                        </p>
                    )}

                    {digest.items.length === 0 ? (
                        <EmptyFeed
                            headline={evening ? 'A quiet day.' : 'Nothing waiting.'}
                            body="I only keep the things worth coming back to, and today there weren't any. That's a good sign, not a broken one."
                        />
                    ) : (
                        <>
                            <SectionLabel>{evening ? 'What I passed over' : 'Today near you'}</SectionLabel>

                            {/* One grouped card, hairline dividers (SCREENS S8) — a single
                                container, so it reads as a brief and not as a feed. */}
                            <div className="rounded-card border-border bg-card divide-border-soft shadow-card divide-y border">
                                {digest.items.map((item) => (
                                    <button
                                        key={item.opportunity_id}
                                        type="button"
                                        className="flex w-full gap-3 p-4 text-left"
                                        onClick={() => router.visit(`/opportunities/${item.opportunity_id}`)}
                                    >
                                        <Thumb image={item.image} />

                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-baseline justify-between gap-3">
                                                <h3 className="text-ink font-serif text-base font-medium">{item.title}</h3>
                                                <span className="text-meta-row text-meta shrink-0 font-medium">{windowLabel(item)}</span>
                                            </span>
                                            {item.note !== null && (
                                                <span className="text-body-card text-body mt-0.5 block truncate">{item.note}</span>
                                            )}
                                        </span>
                                    </button>
                                ))}
                            </div>

                            <div className="flex items-center justify-between">
                                <span className="text-quiet text-xs">Save any to today's map</span>
                                <Link href="/digest/today/map">
                                    <TextAction>Open map</TextAction>
                                </Link>
                            </div>
                        </>
                    )}

                    <EditorialLede>I'll be quiet until something is worth it.</EditorialLede>
                </div>
            </div>
        </ProductLayout>
    );
}

/** "until ~12:00", or nothing — an invented deadline is worse than none. */
function windowLabel(item: DigestItem): string {
    if (item.window_ends_at === null) return 'anytime';

    return `until ~${new Date(item.window_ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
}
