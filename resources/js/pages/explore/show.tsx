import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type ExploreSession, type SessionOpportunity } from '@/types/travel';
import { Head, Link, router } from '@inertiajs/react';

/**
 * S2 — the session and its feed.
 *
 * The empty state is not a bug: nothing fills `opportunities` until the scouts
 * land (E5), and the ordering is distance, not a ranking, until the scoring model
 * lands (E7). The page says so rather than pretending.
 */

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Explore', href: '/explore' },
    { title: 'Session', href: '#' },
];

interface ExploreShowProps {
    session: { data: ExploreSession };
    opportunities: { data: SessionOpportunity[] };
}

export default function ExploreShow({ session, opportunities }: ExploreShowProps) {
    const exploreSession = session.data;
    const items = opportunities.data;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Explore session" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="border-sidebar-border/70 flex flex-wrap items-center gap-3 rounded-xl border p-4">
                    <Badge variant={exploreSession.status === 'active' ? 'default' : 'secondary'}>{exploreSession.status}</Badge>
                    <span className="text-sm">
                        {exploreSession.time_budget_minutes} min · {exploreSession.travel_mode} · reach ~
                        {Math.round(exploreSession.reach_meters / 100) / 10} km
                    </span>
                    <Link href={`/trips/${exploreSession.trip_id}`} className="text-sm underline">
                        Trip
                    </Link>

                    {exploreSession.status === 'active' && (
                        <Button size="sm" variant="outline" className="ml-auto" onClick={() => router.post(`/explore/${exploreSession.id}/end`)}>
                            End session
                        </Button>
                    )}
                </div>

                <div className="flex flex-col gap-3">
                    {items.length === 0 && (
                        <div className="border-sidebar-border/70 rounded-xl border border-dashed p-6 text-center">
                            <p className="text-sm font-medium">Nothing to show yet.</p>
                            <p className="text-muted-foreground text-sm">
                                The scouts that fill this feed are not built yet (E5), and ranking arrives with E7.
                            </p>
                        </div>
                    )}

                    {items.map((opportunity) => (
                        <article key={opportunity.id} className="border-sidebar-border/70 rounded-xl border p-4">
                            <div className="flex items-center gap-2">
                                <h2 className="font-medium">{opportunity.title ?? opportunity.place.name}</h2>
                                <Badge variant="secondary">{opportunity.kind}</Badge>
                                {opportunity.distance_meters !== null && (
                                    <span className="text-muted-foreground text-sm">{opportunity.distance_meters} m</span>
                                )}
                            </div>
                            {opportunity.summary && <p className="text-muted-foreground mt-1 text-sm">{opportunity.summary}</p>}
                            <p className="text-muted-foreground mt-2 text-xs">
                                {opportunity.place.type} · source: {opportunity.place.source}
                            </p>
                        </article>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
