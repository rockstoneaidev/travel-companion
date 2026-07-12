import { AppHeader, EditorialLede, PrimaryPill, SectionLabel, TextAction, Thumb, type ThumbImage } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { Head, Link, router } from '@inertiajs/react';

/**
 * Home — "today".
 *
 * It was the starter kit's empty skeleton (three <PlaceholderPattern> boxes). The
 * tempting fix was "nearby things and a map", but that is Explore at a second URL,
 * and duplicating the primary surface is how an app stops making sense.
 *
 * So home is the digest (PRD §12.4 — the daily habit surface, which was built and
 * then ORPHANED: nothing in the app linked to it), plus where you left off, plus
 * what you kept. Three questions a person actually has when they open the app, and
 * no wall of cards competing with the feed.
 *
 * Deliberately NOT a map of everything we know about. That would be Google Maps
 * with our pins, and it would undo the only promise this product makes. The map
 * belongs to a session, where it has a reason to exist (S3).
 */

interface DashboardProps {
    digest: {
        variant: 'morning' | 'evening';
        lede: string;
        subline: string;
        items: { opportunity_id: string; title: string; note: string | null; image: ThumbImage | null }[];
    };
    session: string | null;
    kept: { still_possible: number; total: number };
}

export default function Dashboard({ digest, session, kept }: DashboardProps) {
    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Today" />

                <div className="mx-auto max-w-md space-y-8 px-5 py-8 lg:max-w-2xl">
                    <AppHeader contextStamp={digest.variant === 'evening' ? 'Tonight' : 'Today'} />

                    <div className="space-y-2">
                        {/* Written from real context, never generated — "it's dry until four"
                            is a factual claim, and the LLM is never a source of facts. */}
                        <h1 className="text-headline text-ink font-serif font-medium italic">{digest.lede}</h1>
                        <p className="text-body-card text-body">{digest.subline}</p>
                    </div>

                    {/* The single most useful thing this screen can say — and it used to say
                        nothing at all: you have a session open, and here is the way back. */}
                    <div className="flex flex-wrap items-center gap-4">
                        {session !== null ? (
                            <PrimaryPill onClick={() => router.visit(`/explore/${session}`)}>Back to what's near you</PrimaryPill>
                        ) : (
                            <PrimaryPill onClick={() => router.visit('/explore')}>I have some time</PrimaryPill>
                        )}

                        {kept.total > 0 && (
                            <Link href="/kept">
                                <TextAction>
                                    {kept.still_possible} of {kept.total} kept still possible
                                </TextAction>
                            </Link>
                        )}
                    </div>

                    {digest.items.length > 0 && (
                        <section className="space-y-2">
                            <SectionLabel>{digest.variant === 'evening' ? 'What I passed over' : 'Worth knowing today'}</SectionLabel>

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
