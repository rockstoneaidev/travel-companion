import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Settings → Privacy (PRD §16, SCREENS S10).
 *
 * Written to be read, not to be complied with. Every control here says what it
 * actually does in plain words, because a privacy setting nobody understands is a
 * dark pattern with a nicer name.
 */

interface PrivacyProps {
    privacy: {
        home_zone: { lat: number; lng: number; radius_meters: number } | null;
        profiling_consent: boolean;
        research_consent: boolean;
        retention_days: number;
        default_radius_meters: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Privacy', href: '/settings/privacy' }];

export default function Privacy({ privacy }: PrivacyProps) {
    const [locating, setLocating] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const homeZone = useForm({
        lat: privacy.home_zone?.lat ?? 0,
        lng: privacy.home_zone?.lng ?? 0,
        radius_meters: privacy.home_zone?.radius_meters ?? privacy.default_radius_meters,
    });

    const deletion = useForm({ password: '' });

    const useMyLocation = () => {
        setLocating(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                homeZone.setData((data) => ({ ...data, lat: position.coords.latitude, lng: position.coords.longitude }));
                setLocating(false);
            },
            () => setLocating(false),
            { enableHighAccuracy: true, timeout: 10_000 },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Privacy" />

            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall title="Privacy" description="What I keep, for how long, and where I don't look at all." />

                    <section className="border-border space-y-4 rounded-lg border p-4">
                        <HeadingSmall
                            title="Home zone"
                            description="Somewhere I should never send you and never learn from — your home, usually. Inside it I keep no precise location at all, not even for a day: I know roughly which part of the city you're in, and nothing finer."
                        />

                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="space-y-1">
                                <Label htmlFor="lat">Latitude</Label>
                                <Input
                                    id="lat"
                                    type="number"
                                    step="0.00001"
                                    value={homeZone.data.lat}
                                    onChange={(e) => homeZone.setData('lat', Number(e.target.value))}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="lng">Longitude</Label>
                                <Input
                                    id="lng"
                                    type="number"
                                    step="0.00001"
                                    value={homeZone.data.lng}
                                    onChange={(e) => homeZone.setData('lng', Number(e.target.value))}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="radius">Radius (metres)</Label>
                                <Input
                                    id="radius"
                                    type="number"
                                    value={homeZone.data.radius_meters}
                                    onChange={(e) => homeZone.setData('radius_meters', Number(e.target.value))}
                                />
                                {homeZone.errors.radius_meters && <p className="text-destructive text-xs">{homeZone.errors.radius_meters}</p>}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button variant="outline" onClick={useMyLocation} disabled={locating}>
                                {locating ? 'Finding you…' : 'Use my current location'}
                            </Button>

                            <Button onClick={() => homeZone.put('/settings/privacy/home-zone', { preserveScroll: true })}>
                                {privacy.home_zone === null ? 'Set home zone' : 'Move home zone'}
                            </Button>

                            {privacy.home_zone !== null && (
                                <Button variant="ghost" onClick={() => router.delete('/settings/privacy/home-zone', { preserveScroll: true })}>
                                    Forget it
                                </Button>
                            )}
                        </div>
                    </section>

                    {/* Art. 9(2)(a). Withdrawing must be exactly as easy as giving
                        (Art. 7(3)) — one click, no password, no "are you sure you want to
                        lose your personalised experience". */}
                    <section className="border-border space-y-3 rounded-lg border p-4">
                        <HeadingSmall
                            title="Learning your taste"
                            description="I build a picture of what you enjoy from the places you pick. It's a guess — but because it learns from the kinds of places you choose, it can end up reflecting personal things, like an interest in religious sites. Turning this off stops the learning AND deletes what I concluded: I shouldn't keep a guess I no longer have your permission to have made. You'll still get suggestions, just less about you."
                        />

                        <Button
                            variant={privacy.profiling_consent ? 'default' : 'outline'}
                            onClick={() =>
                                router.put('/settings/privacy/profiling-consent', { consent: !privacy.profiling_consent }, { preserveScroll: true })
                            }
                        >
                            {privacy.profiling_consent ? 'On — turn it off and forget my taste' : 'Off — let it learn my taste'}
                        </Button>
                    </section>

                    <section className="border-border space-y-3 rounded-lg border p-4">
                        <HeadingSmall
                            title="Research consent"
                            description={`Off by default. With it on, your recommendation traces keep their exact coordinates so they can be used to test whether changes to the ranking make it better or worse. With it off, those coordinates are deleted after ${privacy.retention_days} days like everything else.`}
                        />

                        <Button
                            variant={privacy.research_consent ? 'default' : 'outline'}
                            onClick={() =>
                                router.put(
                                    '/settings/privacy/research-consent',
                                    { research_consent: !privacy.research_consent },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {privacy.research_consent ? 'On — turn it off' : 'Off — turn it on'}
                        </Button>
                    </section>

                    <section className="border-border space-y-3 rounded-lg border p-4">
                        <HeadingSmall
                            title="Take your data with you"
                            description="Everything I hold about you, as a file: your trips, what I showed you and why, what you told me back, and what I concluded about your taste."
                        />

                        {/* A real link, so the browser downloads it in the click that asked for it. */}
                        <Button asChild variant="outline">
                            <a href="/settings/privacy/export" download>
                                Download my data
                            </a>
                        </Button>
                    </section>

                    <section className="border-destructive/40 space-y-3 rounded-lg border p-4">
                        <HeadingSmall
                            title="Delete my account"
                            description="Everything, permanently — trips, history, taste profile, and every piece of feedback you ever gave me. This cannot be undone."
                        />

                        {confirmingDelete ? (
                            <div className="space-y-3">
                                <div className="space-y-1">
                                    <Label htmlFor="password">Confirm your password</Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={deletion.data.password}
                                        onChange={(e) => deletion.setData('password', e.target.value)}
                                    />
                                    {deletion.errors.password && <p className="text-destructive text-xs">{deletion.errors.password}</p>}
                                </div>

                                <div className="flex items-center gap-3">
                                    <Button variant="destructive" onClick={() => deletion.delete('/settings/privacy/account')}>
                                        Delete everything
                                    </Button>
                                    <Button variant="ghost" onClick={() => setConfirmingDelete(false)}>
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <Button variant="outline" onClick={() => setConfirmingDelete(true)}>
                                Delete my account
                            </Button>
                        )}
                    </section>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
