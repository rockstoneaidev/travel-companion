import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { lazy, Suspense, useState } from 'react';

// maplibre is ~200KB: lazy, so the settings page does not carry a map nobody opened.
const HomeZoneMap = lazy(() => import('@/components/app/home-zone-map'));

/**
 * WHY the browser would not place you. Thrown away before — and on iOS a site that has
 * once been denied location errors INSTANTLY, forever, so the button did fire, could
 * never succeed, and never said why. lat/lng sat at 0 and it looked broken.
 */
type LocationError = 'denied' | 'unavailable' | 'unsupported' | null;

const LOCATION_MESSAGE: Record<Exclude<LocationError, null>, string> = {
    denied: "Your browser is blocking location for this site — it won't ask again until you allow it in the browser's site settings. Tap the map instead.",
    unavailable: "I couldn't get a fix on where you are. Try again, or tap the map.",
    unsupported: "This browser won't share a location. Tap the map instead.",
};

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
    const [locationError, setLocationError] = useState<LocationError>(null);
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    const homeZone = useForm({
        lat: privacy.home_zone?.lat ?? 0,
        lng: privacy.home_zone?.lng ?? 0,
        radius_meters: privacy.home_zone?.radius_meters ?? privacy.default_radius_meters,
    });

    const deletion = useForm({ password: '' });

    // The home zone is a POINT you have chosen. Null until you choose it — never 0,0,
    // which is a real place in the Gulf of Guinea and not where anybody lives.
    const point = homeZone.data.lat === 0 && homeZone.data.lng === 0 ? null : { lat: homeZone.data.lat, lng: homeZone.data.lng };

    const useMyLocation = () => {
        if (!navigator.geolocation) {
            setLocationError('unsupported');

            return;
        }

        setLocationError(null);
        setLocating(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                homeZone.setData((data) => ({ ...data, lat: position.coords.latitude, lng: position.coords.longitude }));
                setLocating(false);
            },
            // Denied and "no fix" are not the same thing: denied is permanent until a
            // browser setting changes, so retrying is futile and we have to say so.
            (error) => {
                setLocationError(error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable');
                setLocating(false);
            },
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

                        {/*
                         * It used to be Latitude and Longitude — two number fields. Nobody
                         * knows their home address in decimal degrees, so the one control whose
                         * entire job is "never look here" was the one control a person could not
                         * use. Point at it instead. The circle IS the zone.
                         *
                         * No geocoder, deliberately: we hold places, not addresses, and a
                         * third-party geocoder would drag the ODbL boundary and somebody's ToS
                         * into the most privacy-sensitive screen in the app.
                         */}
                        <Suspense fallback={<div className="bg-muted h-64 w-full rounded-lg" />}>
                            <HomeZoneMap
                                value={point}
                                radiusMeters={homeZone.data.radius_meters}
                                onPick={(picked) => homeZone.setData((data) => ({ ...data, lat: picked.lat, lng: picked.lng }))}
                            />
                        </Suspense>

                        <p className="text-muted-foreground text-sm">
                            {point === null
                                ? 'Tap the map where you live — or use your current location, if you are at home now.'
                                : 'Tap again to move it. Nothing is saved until you press the button.'}
                        </p>

                        <div className="max-w-xs space-y-1">
                            <Label htmlFor="radius">How big is it? (metres)</Label>
                            <Input
                                id="radius"
                                type="number"
                                value={homeZone.data.radius_meters}
                                onChange={(e) => homeZone.setData('radius_meters', Number(e.target.value))}
                            />
                            {homeZone.errors.radius_meters && <p className="text-destructive text-xs">{homeZone.errors.radius_meters}</p>}
                        </div>

                        {locationError !== null && <p className="text-muted-foreground text-sm">{LOCATION_MESSAGE[locationError]}</p>}

                        <div className="flex flex-wrap items-center gap-3">
                            {locationError !== 'denied' && (
                                <Button variant="outline" onClick={useMyLocation} disabled={locating}>
                                    {locating ? 'Finding you…' : locationError === null ? 'Use my current location' : 'Try my location again'}
                                </Button>
                            )}

                            <Button
                                onClick={() => homeZone.put('/settings/privacy/home-zone', { preserveScroll: true })}
                                // 0,0 is a real place in the Gulf of Guinea. Saving it would
                                // silence a patch of ocean and leave the actual home learned from.
                                disabled={point === null || homeZone.processing}
                            >
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
