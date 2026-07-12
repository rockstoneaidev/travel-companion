import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Entity resolution', href: '/admin/entity-resolution' },
];

interface PlaceSide {
    id: string;
    name: string;
    source: string;
}

interface ReviewPair {
    decision_id: string;
    candidate: PlaceSide;
    compared: PlaceSide;
    score: number | null;
    distance_meters: number | null;
    signals: Record<string, unknown>;
}

interface Props {
    pairs: ReviewPair[];
    pendingCount: number;
    resolverVersion: string;
}

export default function AdminEntityResolution({ pairs, pendingCount, resolverVersion }: Props) {
    const merge = (pair: ReviewPair) =>
        router.put(`/admin/entity-resolution/${pair.decision_id}/merge`, {
            candidate_place_id: pair.candidate.id,
            compared_place_id: pair.compared.id,
        });

    const keepDistinct = (pair: ReviewPair) => router.put(`/admin/entity-resolution/${pair.decision_id}/distinct`);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Entity resolution" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <p className="text-muted-foreground text-sm">
                    {pendingCount} pair{pendingCount === 1 ? '' : 's'} the resolver refused to guess about · resolver {resolverVersion}. Both places
                    are live and serveable meanwhile — a duplicate is annoying, a false merge is corruption.
                </p>

                {pairs.length === 0 && <p className="text-muted-foreground text-sm">Nothing to review.</p>}

                {pairs.map((pair) => (
                    <div key={pair.decision_id} className="rounded-xl border p-4">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            {pair.score !== null && <Badge variant="default">score {pair.score.toFixed(2)}</Badge>}
                            {pair.distance_meters !== null && <Badge variant="secondary">{pair.distance_meters} m apart</Badge>}
                        </div>

                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {[pair.candidate, pair.compared].map((side, i) => (
                                <div key={side.id} className="rounded-lg border p-3">
                                    <p className="font-medium">{side.name}</p>
                                    <p className="text-muted-foreground text-xs">
                                        {side.source} · {i === 0 ? 'newly resolved' : 'incumbent'}
                                    </p>
                                    <p className="text-muted-foreground mt-1 font-mono text-[10px]">{side.id}</p>
                                </div>
                            ))}
                        </div>

                        {Object.keys(pair.signals).length > 0 && (
                            <pre className="text-muted-foreground mt-3 overflow-x-auto rounded-lg border p-2 text-[11px]">
                                {JSON.stringify(pair.signals, null, 2)}
                            </pre>
                        )}

                        <div className="mt-3 flex gap-2">
                            <Button size="sm" onClick={() => merge(pair)}>
                                Same place — merge
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => keepDistinct(pair)}>
                                Different places — keep both
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
