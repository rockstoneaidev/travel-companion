import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, PauseCircle, PlayCircle, TriangleAlert, X } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Costs', href: '/admin/costs' },
];

/** Money is integer USD micros on the wire (docs/COST.md §2.4). Divide as late as possible. */
function usd(micros: number | null): string {
    if (micros === null) return '—';

    const dollars = micros / 1_000_000;

    // Sub-cent is the NORMAL case here — a whole uncached voice generation is ~$0.0006 —
    // so two decimals would render a working day as "$0.00" and the page would look broken
    // on precisely the days it is telling the truth.
    return dollars > 0 && dollars < 0.01 ? `$${dollars.toFixed(4)}` : `$${dollars.toFixed(2)}`;
}

interface Slice {
    label: string;
    micros: number;
    savedMicros: number;
    calls: number;
    share: number;
}

interface UserSlice {
    userId: number;
    micros: number;
    calls: number;
    capMicros: number;
    spentTodayMicros: number;
}

interface Event {
    occurredAt: string;
    actorKind: string;
    category: string;
    vendor: string;
    resource: string;
    model: string | null;
    promptVersion: string | null;
    userId: number | null;
    regionKey: string | null;
    inputTokens: number;
    outputTokens: number;
    calls: number;
    micros: number;
    wouldHaveMicros: number;
    cached: boolean;
    priceVersion: string;
}

interface Data {
    range: string;
    filters: Record<string, string>;
    totals: { billedMicros: number; savedMicros: number; calls: number; tokens: number; events: number; cacheSavingPercent: number };
    byCategory: Slice[];
    byVendor: Slice[];
    byResource: Slice[];
    byActor: Slice[];
    byModel: Slice[];
    byPromptVersion: Slice[];
    byRegion: Slice[];
    byUser: UserSlice[];
    daily: { day: string; micros: number }[];
    productMetrics: {
        billedMicros: number;
        amortizedMicros: number;
        activeTripMinutes: number;
        recommendationsServed: number;
        perTripHourMicros: number | null;
        perRecommendationMicros: number | null;
        rolledUp: boolean;
    };
    events: Event[];
}

interface Controls {
    paused: boolean;
    spentTodayMicros: number;
    dailyCapMicros: number;
    perUserCapMicros: number;
    priceVersion: string;
    priceDrift: { checked_at: string; drift: unknown[] } | null;
    freeTier: { used: number; allowance: number };
}

const RANGES = ['today', '7d', '30d', 'all'] as const;

/** Every number is a link one level down (COST.md §7.3). No dead ends. */
function drill(current: Data, column: string, value: string) {
    router.get('/admin/costs', { ...current.filters, range: current.range, [column]: value }, { preserveState: true });
}

function clearFilter(current: Data, column: string) {
    const next: Record<string, string> = { ...current.filters, range: current.range };
    delete next[column];
    router.get('/admin/costs', next, { preserveState: true });
}

function Breakdown({ title, slices, column, data }: { title: string; slices: Slice[]; column: string; data: Data }) {
    if (slices.length === 0) return null;

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent className="text-sm">
                <div className="flex flex-col gap-1">
                    {slices.map((slice) => (
                        <button
                            key={slice.label}
                            onClick={() => drill(data, column, slice.label)}
                            className="hover:bg-muted -mx-2 flex items-center gap-2 rounded px-2 py-1 text-left"
                        >
                            <span className="flex-1 truncate">{slice.label}</span>
                            {/* Share, not just absolute: a $2 line is the headline of a $10 day and noise in a $1,000 one. */}
                            <span className="text-muted-foreground w-12 text-right text-xs tabular-nums">{slice.share}%</span>
                            <span className="w-20 text-right font-medium tabular-nums">{usd(slice.micros)}</span>
                        </button>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}

export default function AdminCosts({ data, controls }: { data: Data; controls: Controls }) {
    const page = usePage<{ auth: { permissions: string[] } }>();
    const canPause = page.props.auth.permissions.includes('cost_pause');

    const activeFilters = Object.entries(data.filters ?? {});
    const capPercent = controls.dailyCapMicros > 0 ? Math.min(100, (controls.spentTodayMicros / controls.dailyCapMicros) * 100) : 0;
    const freePercent = controls.freeTier.allowance > 0 ? Math.min(100, (controls.freeTier.used / controls.freeTier.allowance) * 100) : 0;
    const hasDrift = (controls.priceDrift?.drift.length ?? 0) > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Costs" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* ---- Controls: the things that let you stop watching this page (§7.4) ---- */}
                <div className="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card className={controls.paused ? 'border-destructive' : undefined}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">Today vs cap</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">{usd(controls.spentTodayMicros)}</div>
                            <div className="bg-muted mt-2 h-1.5 w-full overflow-hidden rounded-full">
                                <div
                                    className={`h-full rounded-full ${capPercent >= 100 ? 'bg-destructive' : 'bg-primary'}`}
                                    style={{ width: `${capPercent}%` }}
                                />
                            </div>
                            <p className="text-muted-foreground mt-1 text-xs">of {usd(controls.dailyCapMicros)} · cap in config</p>

                            {canPause && (
                                <button
                                    onClick={() => router.post('/admin/costs/pause', { resume: controls.paused })}
                                    className="mt-3 flex items-center gap-1.5 text-xs underline underline-offset-2"
                                >
                                    {controls.paused ? <PlayCircle className="h-3.5 w-3.5" /> : <PauseCircle className="h-3.5 w-3.5" />}
                                    {controls.paused ? 'Resume paid calls' : 'Pause all paid calls'}
                                </button>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">Google free tier</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">
                                {controls.freeTier.used.toLocaleString()}
                                <span className="text-muted-foreground text-base"> / {controls.freeTier.allowance.toLocaleString()}</span>
                            </div>
                            <div className="bg-muted mt-2 h-1.5 w-full overflow-hidden rounded-full">
                                <div className="bg-primary h-full rounded-full" style={{ width: `${freePercent}%` }} />
                            </div>
                            {/* Invisible in every spend view: free-tier usage bills $0 while eating runway. */}
                            <p className="text-muted-foreground mt-1 text-xs">Essentials events this month — when we start paying</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">Saved by caches</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">{usd(data.totals.savedMicros)}</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {data.totals.cacheSavingPercent}% of notional — is shared caching working
                            </p>
                        </CardContent>
                    </Card>

                    <Card className={hasDrift ? 'border-destructive' : undefined}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">Price sheet</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-semibold">{controls.priceVersion}</div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {controls.priceDrift === null ? (
                                    'Drift never checked — every number here is unverified'
                                ) : hasDrift ? (
                                    <span className="text-destructive flex items-center gap-1">
                                        <TriangleAlert className="h-3 w-3" />
                                        {controls.priceDrift.drift.length} rate(s) drifted from upstream
                                    </span>
                                ) : (
                                    'Matches upstream'
                                )}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* ---- Range + active drill-down filters ---- */}
                <div className="flex flex-wrap items-center gap-2">
                    {RANGES.map((r) => (
                        <Link
                            key={r}
                            href="/admin/costs"
                            data={{ ...data.filters, range: r }}
                            preserveState
                            className={`rounded-md border px-2.5 py-1 text-xs ${
                                data.range === r ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'
                            }`}
                        >
                            {r}
                        </Link>
                    ))}

                    <span className="text-muted-foreground mx-1 text-xs">
                        {usd(data.totals.billedMicros)} · {data.totals.events} events · {data.totals.calls} calls
                    </span>

                    {activeFilters.map(([key, value]) => (
                        <button
                            key={key}
                            onClick={() => clearFilter(data, key)}
                            className="bg-muted flex items-center gap-1 rounded-md px-2 py-1 text-xs"
                        >
                            {key}: <span className="font-medium">{String(value)}</span>
                            <X className="h-3 w-3" />
                        </button>
                    ))}

                    <a
                        href={`/admin/costs/export?${new URLSearchParams({ ...data.filters, range: data.range }).toString()}`}
                        className="ml-auto flex items-center gap-1.5 text-xs underline underline-offset-2"
                    >
                        <Download className="h-3.5 w-3.5" /> CSV
                    </a>
                </div>

                {/* ---- The product metrics: PRD §14.3's budget number ---- */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Product economics (real users only)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {data.productMetrics.rolledUp ? (
                            <div className="grid gap-4 sm:grid-cols-4">
                                <div>
                                    <div className="text-2xl font-semibold">{usd(data.productMetrics.perTripHourMicros)}</div>
                                    <p className="text-muted-foreground text-xs">per active trip-hour</p>
                                </div>
                                <div>
                                    <div className="text-2xl font-semibold">{usd(data.productMetrics.perRecommendationMicros)}</div>
                                    {/* conventions/10: "a €0.40 recommendation is a bug." */}
                                    <p className="text-muted-foreground text-xs">per recommendation</p>
                                </div>
                                <div>
                                    <div className="text-2xl font-semibold">{usd(data.productMetrics.amortizedMicros)}</div>
                                    <p className="text-muted-foreground text-xs">amortised (vs {usd(data.productMetrics.billedMicros)} causal)</p>
                                </div>
                                <div>
                                    <div className="text-2xl font-semibold">{Math.round(data.productMetrics.activeTripMinutes / 60)}h</div>
                                    <p className="text-muted-foreground text-xs">active trip time</p>
                                </div>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                No rollup yet — these come from <code>cost_daily</code>, written nightly by <code>RollUpCostsJob</code>. Run{' '}
                                <code>php artisan cost:rollup</code> to fill it now.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* ---- The drill-down. Every row is a link one level down. ---- */}
                <div className="grid auto-rows-min gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Breakdown title="By category" slices={data.byCategory} column="category" data={data} />
                    <Breakdown title="By vendor" slices={data.byVendor} column="vendor" data={data} />
                    <Breakdown title="By resource / SKU" slices={data.byResource} column="resource" data={data} />
                    <Breakdown title="By model" slices={data.byModel} column="model" data={data} />
                    <Breakdown title="By prompt version" slices={data.byPromptVersion} column="prompt_version" data={data} />
                    <Breakdown title="By actor" slices={data.byActor} column="actor_kind" data={data} />
                    <Breakdown title="By region" slices={data.byRegion} column="region_key" data={data} />

                    {/* The abuse view: real users only — a €12 pack build at the top of this
                        table would answer "is a client looping?" wrong every single day. */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Top users (spend, and cap headroom)</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {data.byUser.length === 0 ? (
                                <p className="text-muted-foreground text-xs">No user spend in this range.</p>
                            ) : (
                                <div className="flex flex-col gap-1">
                                    {data.byUser.map((u) => {
                                        const near = u.capMicros > 0 && u.spentTodayMicros / u.capMicros >= 0.8;

                                        return (
                                            <button
                                                key={u.userId}
                                                onClick={() => drill(data, 'user_id', String(u.userId))}
                                                className="hover:bg-muted -mx-2 flex items-center gap-2 rounded px-2 py-1 text-left"
                                            >
                                                <span className="flex-1">user #{u.userId}</span>
                                                {near && (
                                                    <span className="text-destructive flex items-center gap-1 text-xs">
                                                        <TriangleAlert className="h-3 w-3" /> near cap
                                                    </span>
                                                )}
                                                <span className="w-20 text-right font-medium tabular-nums">{usd(u.micros)}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ---- The bottom of the drill-down: the rows themselves ---- */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Events (most recent 100)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th className="py-1 pr-3 font-medium">When</th>
                                        <th className="py-1 pr-3 font-medium">Actor</th>
                                        <th className="py-1 pr-3 font-medium">Vendor / resource</th>
                                        <th className="py-1 pr-3 font-medium">Tokens / calls</th>
                                        <th className="py-1 pr-3 font-medium">User</th>
                                        <th className="py-1 pr-3 text-right font-medium">Billed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.events.map((e, i) => (
                                        <tr key={i} className="border-t">
                                            <td className="py-1 pr-3 whitespace-nowrap">{new Date(e.occurredAt).toLocaleString()}</td>
                                            <td className="py-1 pr-3">{e.actorKind}</td>
                                            <td className="py-1 pr-3">
                                                {e.vendor} · {e.resource}
                                                {e.cached && <span className="text-muted-foreground"> (cached)</span>}
                                            </td>
                                            <td className="py-1 pr-3 tabular-nums">
                                                {e.category === 'llm' ? `${e.inputTokens} / ${e.outputTokens}` : e.calls}
                                            </td>
                                            <td className="py-1 pr-3">{e.userId ?? '—'}</td>
                                            <td className="py-1 pr-3 text-right tabular-nums">
                                                {e.cached ? (
                                                    <span className="text-muted-foreground">
                                                        {usd(0)} (saved {usd(e.wouldHaveMicros)})
                                                    </span>
                                                ) : (
                                                    usd(e.micros)
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
