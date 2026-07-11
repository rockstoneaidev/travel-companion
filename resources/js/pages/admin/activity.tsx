import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin',
    },
    {
        title: 'Activity',
        href: '/admin/activity',
    },
];

interface ActivityRow {
    id: number;
    description: string;
    causer: string | null;
    subject: string | null;
    properties: Record<string, unknown>;
    createdAt: string;
}

export default function AdminActivity({ activity }: { activity: Paginated<ActivityRow> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Activity" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {activity.data.length === 0 && <p className="text-muted-foreground text-sm">No audit-log entries yet.</p>}

                <div className="flex flex-col gap-2">
                    {activity.data.map((entry) => (
                        <div
                            key={entry.id}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-1 rounded-xl border px-4 py-3"
                        >
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <Badge variant="secondary">{entry.description}</Badge>
                                <span className="text-muted-foreground">
                                    by <span className="text-foreground">{entry.causer ?? 'system (CLI)'}</span>
                                </span>
                                {entry.subject && (
                                    <span className="text-muted-foreground">
                                        on <span className="text-foreground">{entry.subject}</span>
                                    </span>
                                )}
                                <span className="text-muted-foreground ml-auto text-xs">{new Date(entry.createdAt).toLocaleString()}</span>
                            </div>
                            {Object.keys(entry.properties).length > 0 && (
                                <pre className="bg-muted text-muted-foreground overflow-x-auto rounded-md px-3 py-2 text-xs">
                                    {JSON.stringify(entry.properties)}
                                </pre>
                            )}
                        </div>
                    ))}
                </div>

                <div className="text-muted-foreground flex items-center justify-between text-sm">
                    <span>
                        {activity.total} entr{activity.total === 1 ? 'y' : 'ies'}
                    </span>
                    <span className="flex gap-4">
                        {activity.prev_page_url && (
                            <Link href={activity.prev_page_url} className="hover:text-foreground" preserveScroll>
                                ← Previous
                            </Link>
                        )}
                        {activity.next_page_url && (
                            <Link href={activity.next_page_url} className="hover:text-foreground" preserveScroll>
                                Next →
                            </Link>
                        )}
                    </span>
                </div>
            </div>
        </AppLayout>
    );
}
