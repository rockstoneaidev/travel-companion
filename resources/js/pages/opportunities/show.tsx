import { EvidenceList, PrimaryPill, QuietAction, SecondaryPill, SectionLabel, WhyYou } from '@/components/passo';
import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

/**
 * S4 — opportunity detail (SCREENS.md): story → WHY YOU → EVIDENCE →
 * actions. All text is template-over-trace (E12 brings the voice); facts
 * never originate here. "Not for me" carries the same deferred-POST undo
 * as the feed.
 */

interface OpportunityShowProps {
    opportunity: { id: string; kind: string; title: string; summary: string | null };
    place: { name: string | null; lat: number | null; lng: number | null; type: string | null; facets: string[] };
    recommendation: { id: string; walk_minutes: number | null };
    explanation: { why_you: string | null; evidence: { text: string }[] };
    image: { url: string; attribution: string | null; license: string | null } | null;
    sessionId: string | null;
}

export default function OpportunityShow({ opportunity, place, recommendation, explanation, image, sessionId }: OpportunityShowProps) {
    const [dismissed, setDismissed] = useState(false);
    const undoTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const feedback = (event: string, metadata: Record<string, string | number | boolean> = {}) => {
        router.post(`/recommendations/${recommendation.id}/feedback`, { event, metadata }, { preserveScroll: true, preserveState: true });
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
        <div className="bg-paper min-h-screen">
            <Head title={opportunity.title} />
            <div className="mx-auto max-w-md space-y-6 px-5 py-8">
                <div className="flex items-baseline justify-between">
                    {sessionId !== null ? (
                        <Link href={`/explore/${sessionId}`} className="text-meta text-xs font-medium">
                            ← Back
                        </Link>
                    ) : (
                        <span />
                    )}
                </div>

                {image !== null ? (
                    <figure className="space-y-1">
                        {/* Warm 35mm treatment (DESIGN §2.4) — a CSS filter is enough in v1. */}
                        <img
                            src={image.url}
                            alt={place.name ?? opportunity.title}
                            className="rounded-photo border-border h-44 w-full border object-cover [filter:sepia(0.14)_contrast(0.96)_saturate(0.9)]"
                        />
                        <figcaption className="text-quiet text-right text-[10px]">
                            {[image.attribution, image.license, 'via Wikimedia Commons'].filter(Boolean).join(' · ')}
                        </figcaption>
                    </figure>
                ) : (
                    <div className="paper-stripe rounded-photo border-border relative h-44 border">
                        <span className="text-quiet absolute bottom-2 left-3 font-mono text-[10px]">photo: {place.name ?? 'place'}</span>
                    </div>
                )}

                <div className="space-y-1">
                    <h1 className="text-title-detail text-ink font-serif font-medium">{opportunity.title}</h1>
                    <p className="text-meta-row text-meta font-medium">
                        {recommendation.walk_minutes !== null && `${Math.round(recommendation.walk_minutes)} min walk`}
                        {place.type !== null && ` · ${place.type.replace(/_/g, ' ')}`}
                    </p>
                </div>

                {opportunity.summary !== null && <p className="text-body-detail text-body">{opportunity.summary}</p>}

                {explanation.why_you !== null && (
                    <div className="border-border-soft border-t pt-5">
                        <WhyYou>{explanation.why_you}</WhyYou>
                    </div>
                )}

                {explanation.evidence.length > 0 && (
                    <div className="border-border-soft border-t pt-5">
                        <EvidenceList items={explanation.evidence} />
                    </div>
                )}

                {place.facets.length > 0 && <SectionLabel>{place.facets.slice(0, 3).join(' · ')}</SectionLabel>}

                <div className="flex items-center gap-3 pt-2">
                    <PrimaryPill onClick={takeMeThere}>Take me there</PrimaryPill>
                    <SecondaryPill onClick={() => feedback('saved')}>Keep</SecondaryPill>
                </div>

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
            </div>
        </div>
    );
}
