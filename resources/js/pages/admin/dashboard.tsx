import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Activity, Coins, Gauge, PauseCircle, ScrollText, TriangleAlert, Users } from 'lucide-react';

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

/** Money crosses the wire as integer USD micros (docs/COST.md §2.4). Divide late. */
interface Cost {
    todayMicros: number;
    monthMicros: number;
    allTimeMicros: number;
    dailyCapMicros: number;
    projectedMonthMicros: number;
    savedTodayMicros: number;
    capReached: boolean;
    paused: boolean;
    topLineItem: { vendor: string; resource: string; micros: number } | null;
}

/**
 * Sub-cent spend is the normal case in Phase 1 — a whole uncached voice generation is
 * about $0.0006 — so two decimals would render most of a real day as "$0.00" and the
 * strip would look broken on the days it is working. Four below a cent, two above.
 */
function usd(micros: number): string {
    const dollars = micros / 1_000_000;

    return dollars > 0 && dollars < 0.01 ? `$${dollars.toFixed(4)}` : `$${dollars.toFixed(2)}`;
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

function MoneyCard({ title, value, hint, tone }: { title: string; value: string; hint: string; tone?: 'alert' }) {
    return (
        <Card className={tone === 'alert' ? 'border-destructive' : undefined}>
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className={`text-3xl font-semibold ${tone === 'alert' ? 'text-destructive' : ''}`}>{value}</div>
                <p className="text-muted-foreground mt-1 text-xs">{hint}</p>
            </CardContent>
        </Card>
    );
}

/**
 * The cost strip (docs/COST.md §7.2).
 *
 * Shows the WHOLE bill — user spend, emulated-admin spend, ingest capex. The wallet
 * does not care who spent it; only product metrics filter by actor (ADMIN §2.4).
 */
function CostStrip({ cost }: { cost: Cost }) {
    const capPercent = cost.dailyCapMicros > 0 ? Math.min(100, (cost.todayMicros / cost.dailyCapMicros) * 100) : 0;

    return (
        <div className="flex flex-col gap-4">
            {(cost.capReached || cost.paused) && (
                <div className="border-destructive text-destructive flex items-center gap-2 rounded-md border p-3 text-sm">
                    {cost.paused ? <PauseCircle className="h-4 w-4" /> : <TriangleAlert className="h-4 w-4" />}
                    <span>
                        {cost.paused
                            ? 'Paid calls are paused manually. The voice serves the template and routing uses the estimator.'
                            : "Today's spend cap is reached. Paid calls are degrading gracefully — the product still serves."}
                    </span>
                </div>
            )}

            <div className="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card className={cost.capReached ? 'border-destructive' : undefined}>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium">Spend today</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className={`text-3xl font-semibold ${cost.capReached ? 'text-destructive' : ''}`}>{usd(cost.todayMicros)}</div>
                        {/* The bar is the cap, and the cap is the thing that actually protects us. */}
                        <div className="bg-muted mt-2 h-1.5 w-full overflow-hidden rounded-full">
                            <div
                                className={`h-full rounded-full ${cost.capReached ? 'bg-destructive' : 'bg-primary'}`}
                                style={{ width: `${capPercent}%` }}
                            />
                        </div>
                        <p className="text-muted-foreground mt-1 text-xs">
                            {Math.round(capPercent)}% of the {usd(cost.dailyCapMicros)} daily cap
                        </p>
                    </CardContent>
                </Card>

                <MoneyCard title="This month" value={usd(cost.monthMicros)} hint={`${usd(cost.projectedMonthMicros)} projected at this burn`} />
                <MoneyCard title="All time" value={usd(cost.allTimeMicros)} hint="Every metered call, ever" />

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium">Biggest line item today</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {cost.topLineItem ? (
                            <>
                                <div className="text-3xl font-semibold">{usd(cost.topLineItem.micros)}</div>
                                <p className="text-muted-foreground mt-1 truncate text-xs">
                                    {cost.topLineItem.vendor} · {cost.topLineItem.resource}
                                </p>
                            </>
                        ) : (
                            <>
                                <div className="text-muted-foreground text-3xl font-semibold">—</div>
                                {/* Caches saved money even on a day nothing was billed, and that is worth saying. */}
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Nothing billed today{cost.savedTodayMicros > 0 ? ` · ${usd(cost.savedTodayMicros)} saved by caches` : ''}
                                </p>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

export default function AdminDashboard({ stats, cost }: { stats: Stats; cost: Cost | null }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Admin" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* null when the operator lacks `costs_view` — the props never carried it. */}
                {cost && <CostStrip cost={cost} />}

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
                            {cost && (
                                <Link href="/admin/costs" className="text-muted-foreground hover:text-foreground flex items-center gap-2">
                                    <Coins className="h-4 w-4" /> Costs &amp; spend
                                </Link>
                            )}
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
