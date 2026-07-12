import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

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
    approved_curated: number;
    last_scout_run: string | null;
}

export default function AdminWorldModel({ regions }: { regions: RegionStatus[] }) {
    const { flash } = usePage<{ flash?: { status?: string } }>().props;

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
                            {region.unresolved_tiles > 0 && <Badge>{region.unresolved_tiles} tiles unresolved</Badge>}
                        </div>

                        <div className="text-muted-foreground mt-2 flex flex-wrap gap-4 text-sm">
                            <span>
                                {Object.entries(region.source_items)
                                    .map(([source, n]) => `${source} ${n.toLocaleString()}`)
                                    .join(' · ') || 'no source items yet'}
                            </span>
                            <span>{region.places.toLocaleString()} canonical places</span>
                            <span>{region.approved_curated} curated approved</span>
                            {region.last_scout_run && <span>last scout {new Date(region.last_scout_run).toLocaleString()}</span>}
                        </div>

                        <div className="mt-3 flex items-center gap-3">
                            <Button size="sm" onClick={() => router.post(`/admin/world-model/${region.key}/build`, {}, { preserveScroll: true })}>
                                Build world model
                            </Button>
                            <a href="/horizon/dashboard" target="_blank" rel="noreferrer" className="text-xs underline">
                                Watch in Horizon
                            </a>
                        </div>
                        <p className="text-muted-foreground mt-2 text-xs">
                            Ingests OSM + Wikidata (+ Overture when an extract is uploaded), then resolves into canonical places in self-chaining
                            batches. Safe to re-run — everything is idempotent.
                        </p>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
