import { PlaceSearch, type PlaceSuggestion } from '@/components/app';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Trip } from '@/types/travel';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Trips', href: '/trips' }];

interface TripsIndexProps {
    trips: {
        data: Trip[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
}

/** A short, readable line for the planned window — echoed on the list so you don't have to
 *  open a trip to remember when it is. Null when no dates are set. */
function tripDates(trip: Trip): string | null {
    const fmt = (iso: string) => new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric' });
    const starts = trip.planned_start_at ? fmt(trip.planned_start_at) : null;
    const departs = trip.departs_at ? fmt(trip.departs_at) : null;

    if (starts !== null && departs !== null) return `${starts} – ${departs}`;
    if (starts !== null) return `from ${starts}`;
    if (departs !== null) return `until ${departs}`;
    return null;
}

export default function TripsIndex({ trips }: TripsIndexProps) {
    const [planning, setPlanning] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trips" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <p className="text-muted-foreground text-sm">
                        {/*
                         * This used to say "you never create one", which was true of the
                         * IMPLICIT path and false of the product: PRD §6.6 has always had a
                         * planner path, and it is the one people actually want — nobody plans
                         * the trip they are already on.
                         */}
                        A trip appears on its own when you start exploring. Plan one ahead if you already know where you are going.
                    </p>

                    <Button size="sm" variant={planning ? 'ghost' : 'default'} onClick={() => setPlanning((open) => !open)}>
                        {planning ? 'Cancel' : 'Plan a trip'}
                    </Button>
                </div>

                {planning && <PlanTripForm onDone={() => setPlanning(false)} />}

                {trips.data.length === 0 && !planning && <p className="text-muted-foreground text-sm">No trips yet.</p>}

                {trips.data.map((trip) => (
                    <div key={trip.id} className="border-sidebar-border/70 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border p-4">
                        {/* The whole row still opens the trip; the Start button is a sibling,
                            not nested inside the link (a button inside an <a> is invalid). */}
                        <Link href={`/trips/${trip.id}`} className="flex min-w-0 flex-1 items-center gap-3">
                            <span className="font-medium">{trip.name ?? 'Untitled trip'}</span>
                            <Badge variant={trip.status === 'active' ? 'default' : 'secondary'}>{trip.status}</Badge>
                            <span className="text-muted-foreground text-sm">{trip.explore_sessions_count ?? 0} sessions</span>
                            {tripDates(trip) !== null && <span className="text-muted-foreground text-sm">· {tripDates(trip)}</span>}
                        </Link>

                        {/* A planned trip's one manual action, right where you can see it —
                            no auto-start, no geofence (Phase 1 is foreground-only). */}
                        {trip.status === 'planned' &&
                            (trip.has_location ? (
                                <Button size="sm" onClick={() => router.post(`/trips/${trip.id}/start`)}>
                                    Start exploring
                                </Button>
                            ) : (
                                <span className="text-muted-foreground text-xs">Add a location to start</span>
                            ))}
                    </div>
                ))}

                <p className="text-muted-foreground text-xs">
                    {trips.meta.total} trips · page {trips.meta.current_page} of {trips.meta.last_page}
                </p>
            </div>
        </AppLayout>
    );
}

/**
 * The planner (PRD §6.6). It opens a **planned** trip, never an active one — "active"
 * begins at your first session there, and that is the clustering's call to make.
 *
 * The anchor is optional and is not a destination list: it is one point, used to
 * pre-scout. Typeahead runs over our OWN geo-core (no geocoder), which keeps it inside
 * the ODbL boundary and off anybody's terms of service.
 */
function PlanTripForm({ onDone }: { onDone: () => void }) {
    const form = useForm<{
        name: string;
        anchor_point: { lat: number; lng: number } | null;
        planned_start_at: string;
        departs_at: string;
    }>({
        name: '',
        anchor_point: null,
        planned_start_at: '',
        departs_at: '',
    });

    const [anchorName, setAnchorName] = useState<string | null>(null);

    const choose = (place: PlaceSuggestion) => {
        setAnchorName(place.name);
        form.setData('anchor_point', { lat: place.location.lat, lng: place.location.lng });

        // Name it after the place unless the traveller has already said what to call it.
        // "Untitled trip" is not a name, it is an apology.
        if (form.data.name.trim() === '') {
            form.setData('name', place.name);
        }
    };

    const submit = () => {
        // Empty date inputs are "not set" — send null, not '', which would fail `date`.
        form.transform((data) => ({
            ...data,
            planned_start_at: data.planned_start_at || null,
            departs_at: data.departs_at || null,
        }));

        form.post('/trips', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
                router.reload();
            },
        });
    };

    return (
        <div className="border-sidebar-border/70 flex flex-col gap-3 rounded-xl border p-4">
            <label className="text-sm font-medium" htmlFor="trip-name">
                What should I call it?
            </label>

            <input
                id="trip-name"
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                placeholder="France, late July"
                className="border-sidebar-border/70 rounded-md border bg-transparent px-3 py-2 text-sm outline-none"
            />

            {form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}

            <div>
                <PlaceSearch onChoose={choose} placeholder="Anywhere in particular? (optional)" label="Anchor" />
                {anchorName !== null && <p className="text-muted-foreground mt-1 text-xs">Anchored on {anchorName}</p>}
            </div>

            {/* When. The departure date is not just a label — it feeds the "last day makes
                everything urgent" behaviour once you're there (the stay-aware horizon). */}
            <div className="flex flex-wrap gap-4">
                <label className="flex flex-col gap-1 text-xs font-medium">
                    Starts <span className="text-muted-foreground font-normal">(optional)</span>
                    <input
                        type="date"
                        value={form.data.planned_start_at}
                        onChange={(event) => form.setData('planned_start_at', event.target.value)}
                        className="border-sidebar-border/70 rounded-md border bg-transparent px-3 py-2 text-sm outline-none"
                    />
                </label>
                <label className="flex flex-col gap-1 text-xs font-medium">
                    Departs <span className="text-muted-foreground font-normal">(optional)</span>
                    <input
                        type="date"
                        value={form.data.departs_at}
                        min={form.data.planned_start_at || undefined}
                        onChange={(event) => form.setData('departs_at', event.target.value)}
                        className="border-sidebar-border/70 rounded-md border bg-transparent px-3 py-2 text-sm outline-none"
                    />
                </label>
            </div>
            {form.errors.departs_at && <p className="text-destructive text-xs">{form.errors.departs_at}</p>}

            <div className="flex items-center gap-3">
                <Button size="sm" onClick={submit} disabled={form.processing || form.data.name.trim() === ''}>
                    {form.processing ? 'Planning…' : 'Plan it'}
                </Button>
                <span className="text-muted-foreground text-xs">It stays planned until your first session there.</span>
            </div>
        </div>
    );
}
