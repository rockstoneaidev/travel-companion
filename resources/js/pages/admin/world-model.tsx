import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'World model', href: '/admin/world-model' },
];

interface RegionStatus {
    key: string;
    name: string;
    source_items: Record<string, number>;
    places: number;
    unresolved_tiles: number;
    curated_approved: number;
    curated_in_review: number;
    pack_candidates: number;
    /** What a click actually drafts: min(target, candidates). The honest cost. */
    pack_target: number;
    build: { phase: string; started_at: string; stalled: boolean } | null;
    boxes: { done: number; total: number; failed: number } | null;
    draft: { target: number; started_at: string } | null;
}

interface WorldModelProps {
    regions: RegionStatus[];
    scouts: { last_run_at: string | null; hit_rate: number | null };
}

export default function AdminWorldModel({ regions, scouts }: WorldModelProps) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

    const anyWorking = regions.some((region) => region.build !== null || region.draft !== null);

    // A build takes an hour. Poll while one is running, so the page can say what is
    // happening instead of nothing — which is what a person stares at right before
    // they press the button a second time.
    useEffect(() => {
        if (!anyWorking) return;

        const timer = setInterval(() => router.reload({ only: ['regions'] }), 5000);

        return () => clearInterval(timer);
    }, [anyWorking]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="World model" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {flash?.status && <p className="rounded-md border p-3 text-sm">{flash.status}</p>}

                {regions.map((region) => (
                    <div key={region.key} className="rounded-xl border p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{region.name}</span>
                            <Badge variant="outline">{region.key}</Badge>
                            {region.build !== null &&
                                (region.build.stalled ? (
                                    // A build that has shown no sign of life for 15 minutes is not
                                    // a build. Staging sat on "building · evidence" with a greyed
                                    // button for HOURS, for a job that was already dead.
                                    <Badge variant="destructive">stalled · {region.build.phase}</Badge>
                                ) : (
                                    <Badge>building · {region.build.phase}</Badge>
                                ))}
                            {region.draft !== null && <Badge>drafting</Badge>}
                            {region.unresolved_tiles > 0 && <Badge variant="secondary">{region.unresolved_tiles} tiles unresolved</Badge>}
                        </div>

                        <div className="text-muted-foreground mt-2 flex flex-wrap gap-4 text-sm">
                            <span>
                                {Object.entries(region.source_items)
                                    .map(([source, n]) => `${source} ${n.toLocaleString()}`)
                                    .join(' · ') || 'no source items yet'}
                            </span>
                            <span>{region.places.toLocaleString()} canonical places</span>
                            {/* "0 approved" and "0 drafted" are completely different problems —
                                one means go and review, the other means the pack was never
                                drafted — and the old page showed them as the same number. */}
                            <span>
                                curated: {region.curated_approved} approved
                                {region.curated_in_review > 0 && ` · ${region.curated_in_review} awaiting review`}
                                {/* The number that says whether you are nearly done or nowhere near.
                                    The selector already excludes anything a human has ruled on, so
                                    this is genuinely "left", not "exists". */}
                                {` · ${region.pack_candidates} left with evidence`}
                                {region.curated_approved === 0 && region.curated_in_review === 0 && ' · none drafted yet'}
                            </span>
                        </div>

                        {region.boxes !== null && (
                            <div className="mt-3">
                                <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                                    <div
                                        className="bg-primary h-full transition-all"
                                        style={{ width: `${Math.round((region.boxes.done / Math.max(1, region.boxes.total)) * 100)}%` }}
                                    />
                                </div>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    box {region.boxes.done} of {region.boxes.total}
                                    {region.boxes.failed > 0 && ` · ${region.boxes.failed} failed (the rest continue)`}
                                </p>
                            </div>
                        )}

                        {/*
                         * Drafting progress, measured by the drafts actually appearing in
                         * review — the LLM answers one candidate at a time, so the count
                         * climbing IS the progress. A spinner would only prove the page is
                         * animating.
                         */}
                        {region.draft !== null && (
                            <div className="mt-3">
                                <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                                    <div
                                        className="bg-primary h-full transition-all"
                                        style={{
                                            width: `${Math.min(100, Math.round((region.curated_in_review / Math.max(1, region.draft.target)) * 100))}%`,
                                        }}
                                    />
                                </div>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    drafted {region.curated_in_review} of ~{region.draft.target} · asking the LLM one place at a time
                                </p>
                            </div>
                        )}

                        <div className="mt-3 flex items-center gap-3">
                            <Button
                                size="sm"
                                // Pressing this five times used to queue five builds of the same
                                // city — five times the Overpass traffic, on a volunteer service
                                // that rate-limits us, to compute an answer we already had.
                                // Only a LIVE build locks the button. A stalled claim is a corpse
                                // holding the door; every phase is idempotent, so restarting is safe.
                                disabled={region.build !== null && !region.build.stalled}
                                onClick={() => router.post(`/admin/world-model/${region.key}/build`, {}, { preserveScroll: true })}
                            >
                                {region.build === null ? 'Build world model' : region.build.stalled ? 'Stalled — build again' : 'Building…'}
                            </Button>
                            {/*
                             * The button that did not exist, which is why the review queue sat
                             * empty for days looking broken. Drafting is deliberate — it calls
                             * the LLM once per candidate and costs real money — but it was also
                             * INVISIBLE, which is a different thing and not a good one.
                             *
                             * NOT disabled while a build runs, and that is deliberate too. Drafting
                             * during an ingest is safe: the resolver only ever creates a place or
                             * attaches a source item to an existing one — it never deletes a place
                             * or re-issues an id — so a box finishing later cannot orphan a draft
                             * made now. And the candidate selector skips any place that already has
                             * a curated item, so drafting again later picks up only what is new.
                             * You never pay for the same place twice.
                             */}
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={region.pack_target === 0}
                                onClick={() => router.post(`/admin/world-model/${region.key}/draft-pack`, {}, { preserveScroll: true })}
                            >
                                {region.pack_target === 0
                                    ? 'Nothing to draft yet'
                                    : `Draft ${region.pack_target} items (${region.pack_target} LLM calls)`}
                            </Button>

                            <a href="/horizon/dashboard" target="_blank" rel="noreferrer" className="text-xs underline">
                                Watch in Horizon
                            </a>
                        </div>

                        <p className="text-muted-foreground mt-2 text-xs">
                            Ingests each open source in turn — OSM one grid box at a time, so no single job can outlive the queue — then resolves into
                            canonical places, fetches photos, and warms the tile cache. Safe to re-run: every phase is idempotent.
                        </p>

                        {/* The question this page kept provoking: can I draft while it is still
                            building? Yes — so say so, instead of leaving it to be guessed. */}
                        <p className="text-muted-foreground mt-1 text-xs">
                            {region.pack_candidates > region.pack_target
                                ? `${region.pack_candidates}+ candidates have evidence; a draft run takes the best ${region.pack_target}. `
                                : ''}
                            Drafting works during a build — it covers whatever is already resolved, and a later run picks up only the new places,
                            never the ones already drafted.
                        </p>
                    </div>
                ))}

                {/* Honestly global: scout_runs has no region column, so a per-region "last
                    scout" would be a number we invented, and this page has done enough of
                    that already. */}
                <p className="text-muted-foreground text-xs">
                    Scouts (all regions): {scouts.last_run_at ? `last run ${new Date(scouts.last_run_at).toLocaleString()}` : 'never run'}
                    {scouts.hit_rate !== null && ` · tile cache ${Math.round(scouts.hit_rate * 100)}% hit (24h)`}
                </p>
            </div>
        </AppLayout>
    );
}
