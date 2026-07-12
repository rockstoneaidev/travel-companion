import { AppHeader, EditorialLede, PrimaryPill, QuietAction } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { Head, router } from '@inertiajs/react';

/**
 * S9 — the door into calibration (SCREENS.md).
 *
 * Sixty seconds is a real ask, so say what it buys and let them refuse. Skipping
 * is a supported outcome, not a failure: α stays 0 and they get honest cold-start
 * ranking rather than a confident guess built on nothing (ONBOARDING §4).
 */
export default function CalibrateWelcome() {
    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Welcome" />
                <div className="mx-auto flex min-h-full max-w-md flex-col justify-center gap-8 px-5 py-12">
                    <AppHeader />

                    <div className="space-y-4">
                        <h1 className="text-headline text-ink font-serif font-medium italic">Before we start.</h1>
                        <p className="text-body-card text-body">
                            Nine quick pairs, about a minute. There's no right answer — I'm learning which way you lean, so I can stay quiet about the
                            rest.
                        </p>
                    </div>

                    <div className="space-y-4">
                        <PrimaryPill onClick={() => router.visit('/calibrate/1')}>Show me the first pair</PrimaryPill>

                        <div className="flex justify-center">
                            <QuietAction onClick={() => router.visit('/explore')}>Skip for now</QuietAction>
                        </div>

                        <EditorialLede>I'll be quiet until something is worth it.</EditorialLede>
                    </div>
                </div>
            </div>
        </ProductLayout>
    );
}
