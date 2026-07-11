import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Trip } from '@/types/travel';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Trips', href: '/trips' }];

interface TripsIndexProps {
    trips: {
        data: Trip[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    };
}

export default function TripsIndex({ trips }: TripsIndexProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trips" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {trips.data.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        No trips yet. A trip appears on its own when you start exploring — you never create one.
                    </p>
                )}

                {trips.data.map((trip) => (
                    <Link key={trip.id} href={`/trips/${trip.id}`} className="border-sidebar-border/70 flex items-center gap-3 rounded-xl border p-4">
                        <span className="font-medium">{trip.name ?? 'Untitled trip'}</span>
                        <Badge variant={trip.status === 'active' ? 'default' : 'secondary'}>{trip.status}</Badge>
                        <span className="text-muted-foreground text-sm">{trip.explore_sessions_count ?? 0} sessions</span>
                    </Link>
                ))}

                <p className="text-muted-foreground text-xs">
                    {trips.meta.total} trips · page {trips.meta.current_page} of {trips.meta.last_page}
                </p>
            </div>
        </AppLayout>
    );
}
