import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type TravelMode } from '@/types/enums';
import { type ExploreSession } from '@/types/travel';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

/**
 * S1 — start an explore session. "I have 3 hours from here" (PRD §6.6).
 *
 * This is a functional page, not the finished Passo screen: the design system in
 * docs/design/DESIGN.md + SCREENS.md is the UI epic's job. The prop contract
 * below is the one that screen will bind to.
 */

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Explore', href: '/explore' }];

interface TravelModeOption {
    value: TravelMode;
    label: string;
}

interface ExploreIndexProps {
    activeSession: { data: ExploreSession } | null;
    travelModeOptions: TravelModeOption[];
}

interface StartSessionForm {
    origin: { lat: number; lng: number };
    time_budget_minutes: number;
    travel_mode: TravelMode;
}

export default function ExploreIndex({ activeSession, travelModeOptions }: ExploreIndexProps) {
    const [locating, setLocating] = useState(false);

    const { data, setData, post, processing, errors } = useForm<StartSessionForm>({
        // Liljeholmen, Stockholm — the test region (PRD §8.0). Overwritten by
        // the browser's geolocation when the user grants it. Phase 1 is
        // foreground-only: we ask once, on this button, and never in the
        // background.
        origin: { lat: 59.31, lng: 18.02 },
        time_budget_minutes: 180,
        travel_mode: 'walk',
    });

    const useMyLocation = () => {
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setData('origin', { lat: position.coords.latitude, lng: position.coords.longitude });
                setLocating(false);
            },
            () => setLocating(false),
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/explore');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Explore" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {activeSession && (
                    <div className="border-sidebar-border/70 rounded-xl border p-4">
                        <p className="text-sm">You have an explore session open.</p>
                        <Link href={`/explore/${activeSession.data.id}`} className="text-sm underline">
                            Resume it
                        </Link>
                    </div>
                )}

                <form onSubmit={submit} className="border-sidebar-border/70 flex max-w-lg flex-col gap-4 rounded-xl border p-4">
                    <h1 className="text-lg font-medium">Find me something</h1>

                    <div className="grid gap-2">
                        <Label htmlFor="time_budget_minutes">Time budget (minutes)</Label>
                        <Input
                            id="time_budget_minutes"
                            type="number"
                            min={15}
                            max={720}
                            value={data.time_budget_minutes}
                            onChange={(event) => setData('time_budget_minutes', Number(event.target.value))}
                        />
                        {errors.time_budget_minutes && <p className="text-destructive text-sm">{errors.time_budget_minutes}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="travel_mode">Travel mode</Label>
                        <select
                            id="travel_mode"
                            className="border-input h-9 rounded-md border bg-transparent px-3 py-1 text-sm"
                            value={data.travel_mode}
                            onChange={(event) => setData('travel_mode', event.target.value as TravelMode)}
                        >
                            {travelModeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {errors.travel_mode && <p className="text-destructive text-sm">{errors.travel_mode}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label>Origin</Label>
                        <p className="text-muted-foreground text-sm">
                            {data.origin.lat.toFixed(4)}, {data.origin.lng.toFixed(4)}
                        </p>
                        <Button type="button" variant="outline" size="sm" onClick={useMyLocation} disabled={locating}>
                            {locating ? 'Locating…' : 'Use my location'}
                        </Button>
                    </div>

                    <Button type="submit" disabled={processing}>
                        Start exploring
                    </Button>
                </form>
            </div>
        </AppLayout>
    );
}
