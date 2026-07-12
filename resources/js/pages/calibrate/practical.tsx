import { AppHeader, ChoicePill, EditorialLede, PrimaryPill } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { Head, useForm } from '@inertiajs/react';

/**
 * S9 — the two practical questions (SCREENS.md, ONBOARDING §3).
 *
 * These seed FRICTION, not taste: how far you will walk is not something you
 * like, and it feeds friction_penalty rather than the facet weights. Getting that
 * wrong would quietly corrupt every recommendation.
 *
 * Both are skippable — the profile's defaults stand (15 minutes, mid price band).
 */

interface Option {
    value: number;
    label: string;
}

interface CalibratePracticalProps {
    practicals: {
        walk: { question: string; options: Option[] };
        price: { question: string; options: Option[] };
    };
}

export default function CalibratePractical({ practicals }: CalibratePracticalProps) {
    const { data, setData, post, processing } = useForm({
        walk_minutes: null as number | null,
        price_band: null as number | null,
    });

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title="Two practical things" />
                <div className="mx-auto max-w-md space-y-8 px-5 py-8">
                    <AppHeader />

                    <h1 className="text-headline text-ink font-serif font-medium italic">Two practical things.</h1>

                    <form
                        className="space-y-8"
                        onSubmit={(event) => {
                            event.preventDefault();
                            post('/calibrate/practical');
                        }}
                    >
                        <div className="space-y-3">
                            <p className="text-body-card text-body">{practicals.walk.question}</p>
                            <div className="flex flex-wrap gap-2">
                                {practicals.walk.options.map((option) => (
                                    <ChoicePill
                                        key={option.value}
                                        type="button"
                                        selected={data.walk_minutes === option.value}
                                        onClick={() => setData('walk_minutes', option.value)}
                                    >
                                        {option.label}
                                    </ChoicePill>
                                ))}
                            </div>
                        </div>

                        <div className="space-y-3">
                            <p className="text-body-card text-body">{practicals.price.question}</p>
                            <div className="flex flex-col items-start gap-2">
                                {practicals.price.options.map((option) => (
                                    <ChoicePill
                                        key={option.value}
                                        type="button"
                                        selected={data.price_band === option.value}
                                        onClick={() => setData('price_band', option.value)}
                                    >
                                        {option.label}
                                    </ChoicePill>
                                ))}
                            </div>
                        </div>

                        <div className="space-y-4">
                            <PrimaryPill type="submit" disabled={processing}>
                                Start exploring
                            </PrimaryPill>
                            <EditorialLede>I'll be quiet until something is worth it.</EditorialLede>
                        </div>
                    </form>
                </div>
            </div>
        </ProductLayout>
    );
}
