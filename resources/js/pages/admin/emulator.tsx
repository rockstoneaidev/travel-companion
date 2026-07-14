import type { CoverageCell, ServedPin } from '@/components/admin/emulator-map';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';

// Lazily, and deliberately not through a barrel: maplibre-gl is ~200 KB and no other
// console page should pay for it (DESIGN §3).
const EmulatorMap = lazy(() => import('@/components/admin/emulator-map'));

/**
 * The position emulator (ADMIN §6) — "the single most valuable dev tool in the console".
 *
 * Drop a pin, draw a walk, play it back at 60×, and watch the machine think: the
 * coverage cone re-aiming, the scouts firing, the reachability gate throwing candidates
 * away, and — in the phone pane — the real feed re-anchoring underneath you as the pin
 * crosses into Hornstull. No more walking to Hornstull to test a re-anchor.
 *
 * Every tick is a REAL context event through the real ingestion boundary; the only thing
 * separating this from a traveller is the `context_source` flag on the session, and
 * everything that must not learn from it reads that flag (ADMIN §14).
 */

interface Emulation {
    id: string;
    origin: { lat: number; lng: number } | null;
    travel_mode: string;
    time_budget_minutes: number;
    heading: number | null;
    started_at: string;
    expires_at: string;
}

interface LogLine {
    at: string | null;
    stage: string;
    line: string;
}

interface DryRunRow {
    place_id: string;
    name: string;
    composite: number | null;
    travel_min: number | null;
    hold?: string;
}

interface DryRun {
    picked: DryRunRow[];
    held: DryRunRow[];
    funnel: { unreachable: number; held: number; near_misses: number; served: number };
    rank_ms: number;
}

interface EmulatorProps {
    emulation: Emulation | null;
    coverage: { cells: CoverageCell[]; origin_cell: string | null; mode: string } | null;
    served: ServedPin[];
    log: LogLine[];
    controls: {
        travel_modes: Array<{ value: string; label: string }>;
        speeds_kmh: Record<string, number>;
        feed_size: number;
        min_drift_meters: number;
        min_interval_seconds: number;
    };
}

const BREADCRUMBS: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Emulator', href: '/admin/emulator' },
];

const MULTIPLIERS = [1, 10, 60] as const;

/** Metres between two points — the playback interpolator's only geometry. */
function metres(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
    const R = 6_371_000;
    const toRad = (d: number) => (d * Math.PI) / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 2 * R * Math.asin(Math.sqrt(h));
}

function bearing(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
    const toRad = (d: number) => (d * Math.PI) / 180;
    const y = Math.sin(toRad(b.lng - a.lng)) * Math.cos(toRad(b.lat));
    const x = Math.cos(toRad(a.lat)) * Math.sin(toRad(b.lat)) - Math.sin(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.cos(toRad(b.lng - a.lng));

    return Math.round(((Math.atan2(y, x) * 180) / Math.PI + 360) % 360);
}

function csrf(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export default function Emulator({ emulation, coverage, served, log, controls }: EmulatorProps) {
    const [pin, setPin] = useState<{ lat: number; lng: number } | null>(emulation?.origin ?? { lat: 59.3103, lng: 18.0227 });
    const [path, setPath] = useState<Array<{ lat: number; lng: number }>>([]);
    const [drawing, setDrawing] = useState(false);
    const [mode, setMode] = useState(emulation?.travel_mode ?? 'walk');
    const [budget, setBudget] = useState(emulation?.time_budget_minutes ?? 180);
    const [multiplier, setMultiplier] = useState<(typeof MULTIPLIERS)[number]>(10);
    const [playing, setPlaying] = useState(false);
    const [dryRun, setDryRun] = useState<DryRun | null>(null);
    const [busy, setBusy] = useState(false);

    // Where along the path we are, in metres from its start. Playback is a distance, not
    // an index: interpolating means the pin moves at the mode's real speed rather than
    // teleporting between the points someone happened to click.
    const travelled = useRef(0);

    const sessionId = emulation?.id ?? null;

    const post = useCallback(async (url: string, body: Record<string, unknown>) => {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        });
    }, []);

    /*
     * Poll for the consequences (2.5 s — ADMIN §6/§8; no Reverb, Phase 1 discipline).
     *
     * Only while an emulation is live, and only the props that change: the map's own
     * viewport and the operator's half-drawn path are React state and must survive.
     */
    useEffect(() => {
        if (sessionId === null) return;

        const timer = window.setInterval(() => {
            router.reload({ only: ['coverage', 'served', 'log'] });
        }, 2_500);

        return () => window.clearInterval(timer);
    }, [sessionId]);

    // The playback loop. One tick per second of wall clock; the pin advances the distance
    // the mode would really cover in `multiplier` seconds, so a 30-minute walk at 60×
    // takes 30 seconds — and every metre of it is a real context event.
    useEffect(() => {
        if (!playing || sessionId === null || path.length < 2) return;

        const speedKmh = controls.speeds_kmh[mode] ?? 5;
        const metresPerTick = ((speedKmh * 1000) / 3600) * multiplier;

        const timer = window.setInterval(() => {
            travelled.current += metresPerTick;

            const at = pointAlong(path, travelled.current);

            if (at === null) {
                setPlaying(false); // walked the whole path

                return;
            }

            setPin(at.point);
            void post('/admin/emulator/positions', {
                session_id: sessionId,
                location: at.point,
                movement: {
                    mode: mode === 'walk' ? 'walking' : mode === 'bike' ? 'cycling' : 'driving',
                    speed_mps: (speedKmh * 1000) / 3600,
                    heading: at.heading,
                },
            });
        }, 1_000);

        return () => window.clearInterval(timer);
    }, [playing, sessionId, path, mode, multiplier, controls.speeds_kmh, post]);

    const start = () => {
        if (pin === null) return;

        setBusy(true);
        router.post('/admin/emulator/sessions', { origin: pin, travel_mode: mode, time_budget_minutes: budget }, { onFinish: () => setBusy(false) });
    };

    const stop = () => {
        setPlaying(false);
        travelled.current = 0;
        router.delete('/admin/emulator/sessions');
    };

    const runDryRun = async () => {
        if (sessionId === null || pin === null) return;

        setBusy(true);

        try {
            const response = await post('/admin/emulator/dry-run', { session_id: sessionId, lat: pin.lat, lng: pin.lng });

            setDryRun(response.ok ? ((await response.json()) as DryRun) : null);
        } finally {
            setBusy(false);
        }
    };

    // Move the pin by hand: report it, so the live feed re-anchors exactly as it would
    // for a phone that had walked there (E46).
    const jumpTo = (at: { lat: number; lng: number }) => {
        setPin(at);

        if (sessionId !== null) {
            void post('/admin/emulator/positions', { session_id: sessionId, location: at });
        }
    };

    const pathLength = useMemo(() => path.reduce((total, point, i) => (i === 0 ? 0 : total + metres(path[i - 1], point)), 0), [path]);

    return (
        <AppLayout breadcrumbs={BREADCRUMBS}>
            <Head title="Position emulator" />

            <div className="flex flex-col gap-4 p-4">
                {/*
                 * The banner ADMIN §6 asks for, in as many words: "an active override is
                 * visibly bannered in the admin UI (an operator forgetting an override on
                 * is a confusing-bug factory)". It cannot be missed and it cannot be
                 * dismissed — the only way to make it go away is to stop emulating.
                 */}
                {emulation !== null && (
                    <div className="flex items-center justify-between gap-4 rounded-md border border-amber-500/60 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <span>
                            <strong>Emulating a position.</strong> Everything this session produces is marked{' '}
                            <code className="rounded bg-amber-500/20 px-1">emulated</code> — never learned from, excluded from cost metrics and from
                            gold traces. Started {new Date(emulation.started_at).toLocaleTimeString()}.
                        </span>
                        <Button size="sm" variant="outline" onClick={stop}>
                            Stop emulating
                        </Button>
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
                    {/* --- map --- */}
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <select
                                className="rounded border px-2 py-1"
                                value={mode}
                                onChange={(e) => setMode(e.target.value)}
                                disabled={emulation !== null}
                            >
                                {controls.travel_modes.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label} · {controls.speeds_kmh[option.value]} km/h
                                    </option>
                                ))}
                            </select>

                            <label className="flex items-center gap-1">
                                budget
                                <input
                                    type="number"
                                    className="w-20 rounded border px-2 py-1"
                                    value={budget}
                                    min={15}
                                    max={720}
                                    onChange={(e) => setBudget(Number(e.target.value))}
                                    disabled={emulation !== null}
                                />
                                min
                            </label>

                            {emulation === null ? (
                                <Button size="sm" onClick={start} disabled={busy || pin === null}>
                                    Start emulating here
                                </Button>
                            ) : (
                                <>
                                    <Button size="sm" variant={drawing ? 'default' : 'outline'} onClick={() => setDrawing((d) => !d)}>
                                        {drawing ? 'Click the map to add points' : 'Draw a path'}
                                    </Button>

                                    {path.length > 0 && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                setPath([]);
                                                travelled.current = 0;
                                                setPlaying(false);
                                            }}
                                        >
                                            Clear path ({Math.round(pathLength)} m)
                                        </Button>
                                    )}

                                    <div className="flex items-center gap-1">
                                        {MULTIPLIERS.map((m) => (
                                            <Button
                                                key={m}
                                                size="sm"
                                                variant={multiplier === m ? 'default' : 'outline'}
                                                onClick={() => setMultiplier(m)}
                                            >
                                                {m}×
                                            </Button>
                                        ))}
                                    </div>

                                    <Button size="sm" onClick={() => setPlaying((p) => !p)} disabled={path.length < 2}>
                                        {playing ? 'Pause' : 'Play walk'}
                                    </Button>

                                    <Button size="sm" variant="outline" onClick={runDryRun} disabled={busy}>
                                        Dry run here
                                    </Button>
                                </>
                            )}
                        </div>

                        <Suspense fallback={<div className="grid h-[520px] place-items-center rounded-md border text-sm">Loading map…</div>}>
                            <EmulatorMap
                                className="h-[520px] w-full overflow-hidden rounded-md border"
                                pin={pin}
                                path={path}
                                coverage={coverage?.cells ?? []}
                                served={served}
                                drawing={drawing}
                                onDropPin={jumpTo}
                                onAddPathPoint={(at) => setPath((p) => [...p, at])}
                            />
                        </Suspense>

                        {coverage !== null && (
                            <p className="text-muted-foreground text-xs">
                                Coverage: {coverage.cells.length} res-8 cells ({coverage.mode}) · origin {coverage.origin_cell} · re-anchors past{' '}
                                {controls.min_drift_meters} m, at most once per {controls.min_interval_seconds}s
                            </p>
                        )}
                    </div>

                    {/* --- phone + log --- */}
                    <div className="flex flex-col gap-4">
                        {emulation !== null && (
                            <div className="flex flex-col gap-2">
                                <h2 className="text-sm font-semibold">What this position serves</h2>
                                {/*
                                 * The real app, as the emulated user. Not a mock of the feed — the
                                 * feed, which is the only version of it worth watching: when the pin
                                 * crosses into Hornstull this iframe re-anchors for real (E46).
                                 */}
                                <iframe
                                    title="Device preview"
                                    src={`/explore/${emulation.id}`}
                                    className="h-[560px] w-full rounded-[24px] border-4 border-neutral-800 bg-white"
                                />
                            </div>
                        )}

                        {dryRun !== null && (
                            <div className="rounded-md border p-3 text-xs">
                                <h2 className="mb-2 text-sm font-semibold">
                                    Dry run — what this pin WOULD serve ({dryRun.rank_ms} ms, nothing written)
                                </h2>
                                <ul className="mb-2 space-y-1">
                                    {dryRun.picked.map((row) => (
                                        <li key={row.place_id} className="flex justify-between gap-2">
                                            <span>{row.name}</span>
                                            <span className="text-muted-foreground">
                                                {row.composite?.toFixed(3) ?? '—'} · {row.travel_min ?? '—'} min
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground">
                                    served {dryRun.funnel.served} · held {dryRun.funnel.held} · near-missed {dryRun.funnel.near_misses} · unreachable{' '}
                                    {dryRun.funnel.unreachable}
                                </p>
                            </div>
                        )}
                    </div>
                </div>

                {/* --- pipeline log --- */}
                {emulation !== null && (
                    <div className="rounded-md border">
                        <h2 className="border-b px-3 py-2 text-sm font-semibold">Pipeline</h2>
                        <ul className="max-h-64 overflow-y-auto p-3 font-mono text-xs">
                            {log.length === 0 && <li className="text-muted-foreground">Nothing yet — move the pin.</li>}
                            {log.map((line, i) => (
                                <li key={`${line.at}-${i}`} className="flex gap-3 py-0.5">
                                    <span className="text-muted-foreground shrink-0">{line.at ? new Date(line.at).toLocaleTimeString() : '—'}</span>
                                    <span className="text-muted-foreground w-14 shrink-0 uppercase">{line.stage}</span>
                                    <span>{line.line}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

/**
 * The point `distance` metres along the path, and the heading there.
 *
 * Returns null once the walk is over — which is what stops playback, rather than a
 * counter someone has to remember to reset.
 */
function pointAlong(path: Array<{ lat: number; lng: number }>, distance: number): { point: { lat: number; lng: number }; heading: number } | null {
    let remaining = distance;

    for (let i = 1; i < path.length; i++) {
        const from = path[i - 1];
        const to = path[i];
        const leg = metres(from, to);

        if (remaining <= leg) {
            const t = leg === 0 ? 0 : remaining / leg;

            return {
                point: { lat: from.lat + (to.lat - from.lat) * t, lng: from.lng + (to.lng - from.lng) * t },
                heading: bearing(from, to),
            };
        }

        remaining -= leg;
    }

    return null;
}
