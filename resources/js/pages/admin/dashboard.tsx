import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Activity, Gauge, ScrollText, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin',
    },
];

interface Stats {
    totalUsers: number;
    operators: number;
    usersLast7Days: number;
    activityLast7Days: number;
}

function StatCard({ title, value, hint }: { title: string; value: number; hint: string }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="text-3xl font-semibold">{value}</div>
                <p className="text-muted-foreground mt-1 text-xs">{hint}</p>
            </CardContent>
        </Card>
    );
}

export default function AdminDashboard({ stats }: { stats: Stats }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Admin" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <StatCard title="Users" value={stats.totalUsers} hint="Total registered accounts" />
                    <StatCard title="Operators" value={stats.operators} hint="Accounts holding a role" />
                    <StatCard title="New users (7d)" value={stats.usersLast7Days} hint="Registered in the last 7 days" />
                    <StatCard title="Admin activity (7d)" value={stats.activityLast7Days} hint="Audit-log entries, last 7 days" />
                </div>

                <div className="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Console</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2 text-sm">
                            <Link href="/admin/users" className="text-muted-foreground hover:text-foreground flex items-center gap-2">
                                <Users className="h-4 w-4" /> Users &amp; roles
                            </Link>
                            <Link href="/admin/activity" className="text-muted-foreground hover:text-foreground flex items-center gap-2">
                                <ScrollText className="h-4 w-4" /> Activity log
                            </Link>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Operations</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2 text-sm">
                            <a
                                href="/horizon"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground flex items-center gap-2"
                            >
                                <Gauge className="h-4 w-4" /> Horizon — queues &amp; failed jobs
                            </a>
                            <a
                                href="/pulse"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground flex items-center gap-2"
                            >
                                <Activity className="h-4 w-4" /> Pulse — exceptions &amp; slow queries
                            </a>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
