import { AppHeader, EditorialLede, PrimaryPill, QuietAction } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * S9 — the door into calibration, and the consent that has to come before it.
 *
 * EXPLICIT CONSENT (GDPR Art. 9(2)(a), DPIA §3.2). We never ask for
 * special-category data, but the taxonomy has a `spiritual` facet and a
 * `religious_sacred` domain, and the profile learns a weight for them — so a person
 * who keeps choosing chapels ends up with a vector that is, in substance, an
 * inferred statement about their religious belief. Art. 6 consent does not cover
 * that.
 *
 * Explicit means a SEPARATE, AFFIRMATIVE, INFORMED act: not a pre-ticked box, not a
 * sentence in a policy nobody opens, and not a side effect of pressing the only
 * button on the screen. So the uncomfortable part is said in plain words, before the
 * questions rather than after, and the box starts empty.
 *
 * Refusing is a supported outcome, not a failure: α stays 0 and the ranking is the
 * honest cold-start vector (ONBOARDING §4, SCORING §6) — an "I don't know you yet"
 * that is true, rather than a confident guess we had no right to make.
 */
export default function CalibrateWelcome({ consented }: { consented: boolean }) {
    const [agreed, setAgreed] = useState(consented);

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

                    {/* The part most products bury. It sits here, in the same type as
                        everything else, because a disclosure nobody reads is not a disclosure. */}
                    <label className="border-border bg-card flex cursor-pointer gap-3 rounded-[14px] border p-4">
                        <input
                            type="checkbox"
                            checked={agreed}
                            onChange={(event) => setAgreed(event.target.checked)}
                            className="accent-terracotta mt-0.5 size-4 shrink-0"
                        />
                        <span className="text-body-card text-body">
                            I'm happy for you to build a picture of my taste from these answers and from what I do next.
                            <span className="text-meta mt-1.5 block">
                                It's a guess about what you'd enjoy — but because it learns from the kinds of places you pick, it can end up
                                reflecting personal things, like an interest in religious sites. You can see exactly what I've concluded, and delete
                                it, at any time.
                            </span>
                        </span>
                    </label>

                    <div className="space-y-4">
                        <PrimaryPill disabled={!agreed} onClick={() => router.post('/calibrate/consent')}>
                            Show me the first pair
                        </PrimaryPill>

                        {/* Refusing must cost nothing and take one tap. A refusal that is harder
                            than agreeing is not a free choice (Art. 4(11)). */}
                        <div className="flex justify-center">
                            <QuietAction onClick={() => router.visit('/explore')}>No thanks — don't learn my taste</QuietAction>
                        </div>

                        <EditorialLede>You'll still get suggestions. They'll just be less about you.</EditorialLede>
                    </div>
                </div>
            </div>
        </ProductLayout>
    );
}
