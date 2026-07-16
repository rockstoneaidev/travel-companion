import { fetchEssentials, type Essential } from '@/lib/essentials';
import { cn } from '@/lib/utils';
import { LifeBuoy, X } from 'lucide-react';
import { useState } from 'react';
import { TextAction } from './buttons';
import { SectionLabel } from './section-label';

/**
 * "I need a…" — the persistent utility surface (the deliberate home for practical amenities,
 * which the discovery feed excludes). One tap from anywhere shows the nearest toilet, pharmacy,
 * charger, shelter or transport, by pure distance, taste- and budget-blind — because a toilet
 * emergency does not care what you like or how much of your day is left.
 */

const LABELS: Record<string, string> = {
    toilet: 'Toilet',
    pharmacy: 'Pharmacy',
    charging_point: 'Charging',
    shelter: 'Shelter',
    transport_hub: 'Transport',
};

function mapsUrl(item: Essential): string {
    return `https://www.google.com/maps/dir/?api=1&destination=${item.lat},${item.lng}&travelmode=walking`;
}

function walkMinutes(meters: number): string {
    const mins = Math.max(1, Math.round(meters / 80)); // ~80 m/min on foot
    return `${mins} min`;
}

type Status = 'loading' | 'ok' | 'empty' | 'denied';

export function EssentialsButton({ className }: { className?: string }) {
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState<Status>('loading');
    const [items, setItems] = useState<Essential[]>([]);

    const openSheet = async () => {
        setOpen(true);
        setStatus('loading');

        const result = await fetchEssentials();

        if (!result.located) {
            setStatus('denied');

            return;
        }

        setItems(result.items);
        setStatus(result.items.length > 0 ? 'ok' : 'empty');
    };

    return (
        <>
            <button
                type="button"
                onClick={openSheet}
                aria-label="Find essentials nearby"
                className={cn(
                    'fixed right-4 bottom-[calc(1.5rem+env(safe-area-inset-bottom))] z-40',
                    'bg-card text-ink border-border shadow-sheet flex size-12 items-center justify-center rounded-full border',
                    className,
                )}
            >
                <LifeBuoy className="size-5" aria-hidden />
            </button>

            {open && (
                <div className="fixed inset-0 z-50 flex items-end justify-center" role="dialog" aria-modal="true" aria-label="Essentials nearby">
                    <button type="button" aria-label="Close" className="absolute inset-0 bg-black/25" onClick={() => setOpen(false)} />

                    <div className="bg-paper shadow-sheet relative z-10 w-full max-w-md rounded-t-2xl p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))]">
                        <div className="flex items-center justify-between">
                            <SectionLabel>Essentials nearby</SectionLabel>
                            <button type="button" onClick={() => setOpen(false)} aria-label="Close" className="text-meta hover:text-ink">
                                <X className="size-5" />
                            </button>
                        </div>

                        {status === 'loading' && <p className="text-body mt-4 text-sm">Finding what’s nearest…</p>}

                        {status === 'denied' && (
                            <p className="text-body mt-4 text-sm">Turn on location to find the nearest toilet, pharmacy or charger.</p>
                        )}

                        {status === 'empty' && <p className="text-body mt-4 text-sm">Nothing essential within reach of here.</p>}

                        {status === 'ok' && (
                            <ul className="divide-border-soft mt-3 divide-y">
                                {items.map((item, index) => (
                                    <li key={`${item.type}-${index}`} className="flex items-center gap-3 py-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-meta text-xs tracking-[.12em] uppercase">{LABELS[item.type] ?? item.type}</p>
                                            <p className="text-ink truncate font-serif">{item.name || (LABELS[item.type] ?? 'Essential')}</p>
                                        </div>
                                        <span className="text-meta text-sm">{walkMinutes(item.distance_m)}</span>
                                        <a href={mapsUrl(item)} target="_blank" rel="noopener noreferrer">
                                            <TextAction>Take me</TextAction>
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
