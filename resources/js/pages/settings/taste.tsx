import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Settings → Taste (SCREENS S10).
 *
 * Show what we think we know, in plain words, BEFORE offering to throw it away.
 * "Reset my taste profile" is not a button anyone should have to press blind —
 * and if what we show here is wrong, the button is exactly the right response.
 */

interface TasteProps {
    taste: {
        calibrated: boolean;
        alpha: number;
        leans_toward: string[];
        leans_away: string[];
        walk_tolerance_minutes: number;
        price_band: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Taste', href: '/settings/taste' }];

const PRICE_BANDS: Record<number, string> = { 1: 'keep it cheap', 2: 'somewhere in the middle', 3: "price doesn't matter" };

export default function Taste({ taste }: TasteProps) {
    const [confirming, setConfirming] = useState(false);

    // How much of your ranking is actually YOU yet, rather than the cold-start
    // average (SCORING §6). Saying "34%" is more honest than a progress bar.
    const share = Math.round(taste.alpha * 100);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Taste" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Your taste" description="What I've worked out about you so far — and how to make me forget it." />

                    <div className="space-y-4 text-sm">
                        {taste.calibrated && share >= 100 ? (
                            // α saturates once there is enough real signal (SCORING §6).
                            // Saying "100% — and the rest is cold-start" is a sentence that
                            // argues with itself.
                            <p>
                                Your ranking is <strong>entirely yours</strong> now — I'm going on what you've actually done, not on an average of
                                everyone.
                            </p>
                        ) : taste.calibrated ? (
                            <p>
                                About <strong>{share}%</strong> of what you're shown is based on you. The rest is still the general cold-start ranking
                                — it shifts your way as I learn.
                            </p>
                        ) : (
                            <p>
                                I don't know you yet, so everything you're shown is the general ranking.{' '}
                                <button type="button" className="underline underline-offset-[3px]" onClick={() => router.visit('/welcome')}>
                                    Nine quick pairs
                                </button>{' '}
                                would fix that.
                            </p>
                        )}

                        {taste.leans_toward.length > 0 && (
                            <p>
                                You lean toward <strong>{taste.leans_toward.join(', ').replace(/_/g, ' ')}</strong>
                                {taste.leans_away.length > 0 && (
                                    <>
                                        , and away from <strong>{taste.leans_away.join(', ').replace(/_/g, ' ')}</strong>
                                    </>
                                )}
                                .
                            </p>
                        )}

                        <p className="text-muted-foreground">
                            You'll walk about {taste.walk_tolerance_minutes} minutes for something good, and on food:{' '}
                            {PRICE_BANDS[taste.price_band] ?? 'somewhere in the middle'}.
                        </p>
                    </div>

                    <div className="border-border space-y-3 rounded-lg border p-4">
                        <HeadingSmall
                            title="Reset my taste profile"
                            description="If I've got you wrong, I'd rather start over than keep guessing confidently. This forgets what I concluded about you — not what you did. Your saved places and history stay."
                        />

                        {confirming ? (
                            <div className="flex items-center gap-3">
                                <Button variant="destructive" onClick={() => router.delete('/settings/taste')}>
                                    Yes — forget my taste
                                </Button>
                                <Button variant="ghost" onClick={() => setConfirming(false)}>
                                    Keep it
                                </Button>
                            </div>
                        ) : (
                            <Button variant="outline" onClick={() => setConfirming(true)}>
                                Reset my taste profile
                            </Button>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
