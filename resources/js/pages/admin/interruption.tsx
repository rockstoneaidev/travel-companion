import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Interruption quality', href: '/admin/interruption' },
];

interface Metrics {
    considered: number;
    allowed: number;
    silenceRate: number;
    sent: number;
    opened: number;
    dismissed: number;
    acceptanceRate: number;
    annoyanceRate: number;
    denialsByGate: Record<string, number>;
    tripModeStarted: number;
    tripModeAbandoned: number;
    abandonmentRate: number;
    budgetSaturatedDays: number;
    range: string;
    policyVersion: string;
}

interface ExitCriteria {
    min_acceptance_rate: number;
    max_annoyance_rate: number;
    max_trip_mode_abandonment: number;
    min_sample_pushes: number;
}

const pct = (n: number) => `${Math.round(n * 100)}%`;

const GATE_LABELS: Record<string, string> = {
    trip_mode_off: 'Trip Mode off',
    quiet_hours: 'Quiet hours',
    driving: 'Driving',
    cooldown: 'Cooldown',
    daily_budget: 'Daily budget (3/day)',
    low_confidence: 'Low confidence',
    not_open: 'Not open now',
    detour_too_far: 'Detour too far',
    stale_evidence: 'Stale evidence',
    category_rejected: 'Category rejected',
    not_pushable: 'Not pushable',
};

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
            {sub !== undefined && <div className="text-muted-foreground mt-1 text-xs tabular-nums">{sub}</div>}
        </div>
    );
}

export default function AdminInterruption({ metrics, exitCriteria }: { metrics: Metrics; exitCriteria: ExitCriteria }) {
    const enoughData = metrics.sent >= exitCriteria.min_sample_pushes;

    // The verdict, honestly gated on sample size: a rate over a handful of pushes is a
    // coincidence wearing a percentage, so below the floor the answer is "not yet".
    const verdict = !enoughData
        ? { label: 'Not enough data yet', tone: 'secondary' as const }
        : metrics.acceptanceRate >= exitCriteria.min_acceptance_rate &&
            metrics.annoyanceRate <= exitCriteria.max_annoyance_rate &&
            metrics.abandonmentRate <= exitCriteria.max_trip_mode_abandonment
          ? { label: 'Passing', tone: 'default' as const }
          : { label: 'Below target', tone: 'destructive' as const };

    const setRange = (range: string) => router.get('/admin/interruption', { range }, { preserveState: true, preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Interruption quality" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <header className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Can we interrupt at the right time?</h1>
                        <p className="text-muted-foreground text-sm">MVP question 3 · policy {metrics.policyVersion} · exit read</p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge variant={verdict.tone}>{verdict.label}</Badge>
                        <select value={metrics.range} onChange={(e) => setRange(e.target.value)} className="rounded-md border px-2 py-1 text-sm">
                            <option value="24h">24h</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                </header>

                {/* The three exit criteria, current vs. target. Set instrument-first, so this
                    is a verdict and not a rationalisation. */}
                <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Stat
                        label={`Acceptance (target ≥ ${pct(exitCriteria.min_acceptance_rate)})`}
                        value={pct(metrics.acceptanceRate)}
                        sub={`${metrics.opened} opened of ${metrics.sent} sent`}
                    />
                    <Stat
                        label={`Annoyance (target ≤ ${pct(exitCriteria.max_annoyance_rate)})`}
                        value={pct(metrics.annoyanceRate)}
                        sub={`${metrics.dismissed} swiped of ${metrics.sent} sent`}
                    />
                    <Stat
                        label={`Trip Mode abandonment (target ≤ ${pct(exitCriteria.max_trip_mode_abandonment)})`}
                        value={pct(metrics.abandonmentRate)}
                        sub={`${metrics.tripModeAbandoned} of ${metrics.tripModeStarted} turned it off mid-trip`}
                    />
                </section>

                {/* Restraint is the product. A high silence rate is the gates doing their job —
                    the interesting half of a notification policy is what it did NOT send. */}
                <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Stat
                        label="Silence rate"
                        value={pct(metrics.silenceRate)}
                        sub={`${metrics.considered - metrics.allowed} of ${metrics.considered} held back`}
                    />
                    <Stat label="Allowed" value={String(metrics.allowed)} sub={`of ${metrics.considered} considered`} />
                    <Stat label="Budget-saturated days" value={String(metrics.budgetSaturatedDays)} sub="wanted to send a 4th" />
                </section>

                <section>
                    <h2 className="mb-3 text-sm font-medium">Why we stayed quiet</h2>
                    {Object.keys(metrics.denialsByGate).length === 0 ? (
                        <p className="text-muted-foreground text-sm">No denials in range.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {Object.entries(metrics.denialsByGate)
                                .sort(([, a], [, b]) => b - a)
                                .map(([gate, n]) => (
                                    <li key={gate} className="flex items-center justify-between px-4 py-2 text-sm">
                                        <span>{GATE_LABELS[gate] ?? gate}</span>
                                        <span className="tabular-nums">{n}</span>
                                    </li>
                                ))}
                        </ul>
                    )}
                </section>

                <p className="text-muted-foreground text-xs">
                    Battery-complaint rate, permission-grant rate per power tier, and the “why did I get this” open rate are collected on the handset
                    and land here when the mobile client (E34) ships. They are absent, not zero.
                </p>
            </div>
        </AppLayout>
    );
}
