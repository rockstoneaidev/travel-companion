import { EvidenceList, NavMenu, PrimaryPill, QuietAction, SecondaryPill, SectionLabel, StalenessLine, WhyYou } from '@/components/app';
import { useOnline } from '@/hooks/use-online';
import ProductLayout from '@/layouts/product-layout';
import { sendFeedback } from '@/lib/feedback';
import { travelMeta } from '@/lib/travel-time';
import { type TravelMode } from '@/types/enums';
import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

/**
 * S4 — opportunity detail (SCREENS.md): story → WHY YOU → EVIDENCE →
 * actions. All text is template-over-trace (E12 brings the voice); facts
 * never originate here. "Not for me" carries the same deferred-POST undo
 * as the feed.
 *
 * `recommendation` is NULL for anything the ranker weighed and held back — the home
 * screen's hero, its "Also worth knowing" rows and its dimmed pins are all built from
 * exactly those (see OpportunityController). The page still opens, because refusing to
 * show you a place you just tapped is not a defensible answer; it simply has no trace to
 * explain and no served item to hold an opinion about, and it says so rather than
 * offering buttons that would have nowhere to write.
 */

interface OpportunityShowProps {
    opportunity: { id: string; kind: string; title: string; summary: string | null };
    place: { name: string | null; lat: number | null; lng: number | null; type: string | null; facets: string[] };
    recommendation: { id: string; travel_minutes: number | null; travel_mode: TravelMode } | null;
    explanation: { why_you: string | null; evidence: { text: string }[] } | null;
    image: { url: string; attribution: string | null; license: string | null } | null;
    sessionId: string | null;
}

export default function OpportunityShow({ opportunity, place, recommendation, explanation, image, sessionId }: OpportunityShowProps) {
    const { online, lastFreshAt } = useOnline('opportunity');
    const [dismissed, setDismissed] = useState(false);
    // A Commons URL can resolve on our server yet fail to load here (on-demand thumbnail
    // rate-limiting); a failed load is no-photo, not breakage — fall to the designed stripe.
    const [imageFailed, setImageFailed] = useState(false);
    const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Queued to disk first, sent second (S11). "Not for me" tapped in a dead zone
    // is still an opinion, and "Take me there" hands off to a maps app that has its
    // own offline story — neither may depend on our network.
    const feedback = (event: string, metadata: Record<string, string | number | boolean> = {}) => {
        if (recommendation === null) return; // nothing was served; there is nothing to attach this to
        sendFeedback(recommendation.id, event, metadata);
    };

    const takeMeThere = () => {
        feedback('accepted', { started_navigation: true });
        window.open(`https://www.google.com/maps/dir/?api=1&destination=${place.lat},${place.lng}&travelmode=walking`, '_blank');
    };

    const notForMe = () => {
        setDismissed(true);
        undoTimer.current = setTimeout(() => {
            feedback('dismissed');
            if (sessionId !== null) router.visit(`/explore/${sessionId}`);
        }, 5000);
    };

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title={opportunity.title} />
                <div className="mx-auto max-w-md space-y-6 px-5 py-8">
                    {!online && <StalenessLine lastFreshAt={lastFreshAt} />}
                    <div className="flex items-center gap-2">
                        <NavMenu />
                        {sessionId !== null && (
                            <Link href={`/explore/${sessionId}`} className="text-meta hover:text-ink text-xs font-medium">
                                ← Back
                            </Link>
                        )}
                    </div>

                    {image !== null && !imageFailed ? (
                        <figure className="space-y-1">
                            {/* Warm 35mm treatment (DESIGN §2.4) — a CSS filter is enough in v1. */}
                            <img
                                src={image.url}
                                alt={place.name ?? opportunity.title}
                                onError={() => setImageFailed(true)}
                                className="rounded-photo border-border h-44 w-full border object-cover [filter:sepia(0.14)_contrast(0.96)_saturate(0.9)]"
                            />
                            <figcaption className="text-quiet text-right text-[10px]">
                                {[image.attribution, image.license, 'via Wikimedia Commons'].filter(Boolean).join(' · ')}
                            </figcaption>
                        </figure>
                    ) : (
                        // No Commons image for this place: the paper stripe IS the
                        // designed state (SCREENS build note 6) — no caption, no
                        // apology, and never debug-style text in product UI (DESIGN §2.3).
                        <div className="paper-stripe rounded-photo border-border h-28 border" aria-hidden="true" />
                    )}

                    <div className="space-y-1">
                        <h1 className="text-title-detail text-ink font-serif font-medium">{opportunity.title}</h1>
                        <p className="text-meta-row text-meta font-medium">
                            {recommendation?.travel_minutes != null && travelMeta(recommendation.travel_minutes, recommendation.travel_mode)}
                            {place.type !== null && `${recommendation?.travel_minutes != null ? ' · ' : ''}${place.type.replace(/_/g, ' ')}`}
                        </p>
                    </div>

                    {opportunity.summary !== null && <p className="text-body-detail text-body">{opportunity.summary}</p>}

                    {explanation !== null && explanation.why_you !== null && (
                        <div className="border-border-soft border-t pt-5">
                            <WhyYou>{explanation.why_you}</WhyYou>
                        </div>
                    )}

                    {explanation !== null && explanation.evidence.length > 0 && (
                        <div className="border-border-soft border-t pt-5">
                            <EvidenceList items={explanation.evidence} />
                        </div>
                    )}

                    {place.facets.length > 0 && <SectionLabel>{place.facets.slice(0, 3).join(' · ')}</SectionLabel>}

                    {/*
                     * The passed-over state. The map already tells the user these are ones I
                     * "considered but didn't suggest", so the honest thing on arrival is to
                     * say the same thing in the same voice — not to fake a recommendation, and
                     * not to offer Keep / Not-for-me, which would have no served item to write
                     * against. "Take me there" still stands: the place is real and reachable
                     * whatever the ranker thought of it.
                     */}
                    {recommendation === null && (
                        <p className="text-body-card text-meta border-border-soft border-t pt-5">
                            I weighed this one and passed it over — it didn't make today's few. It's here so you can see what I set aside.
                        </p>
                    )}

                    <div className="flex items-center gap-3 pt-2">
                        <PrimaryPill onClick={takeMeThere}>Take me there</PrimaryPill>
                        {recommendation !== null && <SecondaryPill onClick={() => feedback('saved')}>Keep</SecondaryPill>}
                    </div>

                    {recommendation !== null && (
                        <div className="pt-1 text-center">
                            {dismissed ? (
                                <span className="text-meta text-xs">
                                    Okay — fewer like this.{' '}
                                    <button
                                        className="text-ink font-semibold underline underline-offset-[3px]"
                                        onClick={() => {
                                            clearTimeout(undoTimer.current ?? undefined);
                                            setDismissed(false);
                                        }}
                                    >
                                        Undo
                                    </button>
                                </span>
                            ) : (
                                <QuietAction onClick={notForMe}>Not for me — fewer like this</QuietAction>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </ProductLayout>
    );
}
