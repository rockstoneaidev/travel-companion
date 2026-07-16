import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Trip } from '@/types/travel';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Trips', href: '/trips' },
    { title: 'Trip', href: '#' },
];

interface TripShowProps {
    trip: { data: Trip };
}

/**
 * When the trip is planned to run. The departure date is load-bearing, not decorative: once
 * the trip is live it feeds the stay-aware urgency horizon (E38) — evergreen places stay
 * calm early in the stay, and the last day makes everything urgent, for free.
 */
function TripDates({ tripId, plannedStartAt, departsAt }: { tripId: string; plannedStartAt: string | null; departsAt: string | null }) {
    const toDate = (iso: string | null) => (iso ? iso.slice(0, 10) : '');
    const { data, setData, patch, transform, processing } = useForm<{ planned_start_at: string; departs_at: string }>({
        planned_start_at: toDate(plannedStartAt),
        departs_at: toDate(departsAt),
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        // Empty means "unset" — send null, not '', which would fail the `date` rule.
        transform((d) => ({ planned_start_at: d.planned_start_at || null, departs_at: d.departs_at || null }));
        patch(`/trips/${tripId}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={save} className="border-sidebar-border/70 flex max-w-lg flex-wrap items-end gap-3 rounded-xl border p-4">
            <label className="flex flex-col gap-1 text-sm font-medium">
                Starts
                <Input type="date" value={data.planned_start_at} onChange={(e) => setData('planned_start_at', e.target.value)} />
            </label>
            <label className="flex flex-col gap-1 text-sm font-medium">
                Departs
                <Input
                    type="date"
                    value={data.departs_at}
                    min={data.planned_start_at || undefined}
                    onChange={(e) => setData('departs_at', e.target.value)}
                />
            </label>
            <Button type="submit" variant="outline" disabled={processing}>
                Save dates
            </Button>
        </form>
    );
}

export default function TripShow({ trip }: TripShowProps) {
    const item = trip.data;

    const { data, setData, patch, processing, errors } = useForm<{ name: string }>({
        name: item.name ?? '',
    });

    const rename = (event: FormEvent) => {
        event.preventDefault();
        patch(`/trips/${item.id}`, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={item.name ?? 'Trip'} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="border-sidebar-border/70 flex items-center gap-3 rounded-xl border p-4">
                    <Badge variant={item.status === 'active' ? 'default' : 'secondary'}>{item.status}</Badge>
                    <Badge variant="outline">{item.source}</Badge>
                    <span className="text-muted-foreground text-sm">{item.explore_sessions_count ?? 0} sessions</span>

                    <div className="ml-auto flex items-center gap-2">
                        {/* The door out of "planned". A planned trip with a location can be
                            started: it activates and drops you into a live session there. */}
                        {item.status === 'planned' && item.has_location && (
                            <Button size="sm" onClick={() => router.post(`/trips/${item.id}/start`)}>
                                Start exploring
                            </Button>
                        )}
                        {item.status === 'planned' && !item.has_location && (
                            <span className="text-muted-foreground text-xs">Add a location to start exploring.</span>
                        )}
                        {item.status !== 'completed' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.patch(`/trips/${item.id}`, { status: 'completed' }, { preserveScroll: true })}
                            >
                                Mark ended
                            </Button>
                        )}
                    </div>
                </div>

                <form onSubmit={rename} className="border-sidebar-border/70 flex max-w-lg items-end gap-2 rounded-xl border p-4">
                    <div className="grid flex-1 gap-2">
                        <label htmlFor="name" className="text-sm font-medium">
                            Name
                        </label>
                        <Input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} />
                        {errors.name && <p className="text-destructive text-sm">{errors.name}</p>}
                    </div>
                    <Button type="submit" disabled={processing}>
                        Rename
                    </Button>
                </form>

                <TripDates tripId={item.id} plannedStartAt={item.planned_start_at ?? null} departsAt={item.departs_at ?? null} />

                <div className="flex flex-col gap-2">
                    {(item.explore_sessions ?? []).map((session) => (
                        <Link
                            key={session.id}
                            href={`/explore/${session.id}`}
                            className="border-sidebar-border/70 flex items-center gap-3 rounded-xl border p-3 text-sm"
                        >
                            <Badge variant="secondary">{session.status}</Badge>
                            <span>
                                {session.time_budget_minutes} min · {session.travel_mode}
                            </span>
                            {/* Labelled, because it wasn't: a bare timestamp next to the word
                                "ended" reads as the time it ENDED. It is the time it began. */}
                            <span className="text-muted-foreground">started {new Date(session.started_at).toLocaleString()}</span>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
