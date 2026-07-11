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

                    {item.status !== 'completed' && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            onClick={() => router.patch(`/trips/${item.id}`, { status: 'completed' }, { preserveScroll: true })}
                        >
                            Mark ended
                        </Button>
                    )}
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
                            <span className="text-muted-foreground">{new Date(session.started_at).toLocaleString()}</span>
                        </Link>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
