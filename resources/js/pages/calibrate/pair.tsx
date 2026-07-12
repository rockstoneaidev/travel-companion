import { AppHeader, ProgressSegments, QuietAction } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * S9 — one forced-choice pair (SCREENS.md, ONBOARDING §2).
 *
 * Two stacked cards. Tap to choose: a brief ink border confirms, then it
 * auto-advances. No "both", no back button, no scores on screen.
 *
 * The facets are NOT in this payload, and that is deliberate. A person who can
 * see that one card is tagged "offbeat" stops telling us their taste and starts
 * telling us what they want us to think.
 */

interface PairSide {
    caption: string;
    image: string | null;
}

interface CalibratePairProps {
    pair: { number: number; a: PairSide; b: PairSide };
    total: number;
    answered: number;
}

export default function CalibratePair({ pair, total, answered }: CalibratePairProps) {
    const [chosen, setChosen] = useState<'a' | 'b' | null>(null);

    // Inertia re-renders THIS SAME component with new props rather than remounting
    // it, so `chosen` survives the navigation to the next pair. Without this reset
    // the flow is dead after pair 1: card A stays bordered, card B stays dimmed,
    // and the "one answer per pair" guard below refuses every subsequent tap.
    //
    // No backend test can see this — they POST straight to the route and never
    // render the component. It took driving the actual browser.
    useEffect(() => setChosen(null), [pair.number]);

    const answer = (side: 'a' | 'b' | null) => {
        if (chosen !== null) return;   // one answer per pair; a double-tap is not two opinions

        setChosen(side);

        // Brief confirm, then advance — the pause is what makes the choice feel
        // registered rather than snatched away.
        window.setTimeout(() => router.post(`/calibrate/${pair.number}`, { side }), side === null ? 0 : 220);
    };

    return (
        <ProductLayout>
            <div className="bg-paper min-h-full flex-1">
                <Head title={`${pair.number} of ${total}`} />
                <div className="mx-auto max-w-md space-y-6 px-5 py-8">
                    <AppHeader contextStamp={`${pair.number} of ${total}`} />

                    <ProgressSegments total={total} current={answered} />

                    <div className="space-y-1">
                        <h1 className="text-headline text-ink font-serif font-medium italic">Which pulls you in?</h1>
                        <p className="text-body-card text-body">There's no right answer — this is how I learn your taste.</p>
                    </div>

                    <div className="space-y-4">
                        {(['a', 'b'] as const).map((side) => (
                            <PairCard
                                key={side}
                                side={pair[side]}
                                chosen={chosen === side}
                                dimmed={chosen !== null && chosen !== side}
                                onChoose={() => answer(side)}
                            />
                        ))}
                    </div>

                    <div className="flex justify-center">
                        <QuietAction onClick={() => answer(null)}>Skip this one</QuietAction>
                    </div>
                </div>
            </div>
        </ProductLayout>
    );
}

function PairCard({
    side,
    chosen,
    dimmed,
    onChoose,
}: {
    side: PairSide;
    chosen: boolean;
    dimmed: boolean;
    onChoose: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onChoose}
            className={cn(
                'rounded-card bg-card block w-full overflow-hidden text-left transition-all duration-150',
                chosen ? 'border-ink border-2' : 'border-border shadow-card border',
                dimmed && 'opacity-40',
            )}
        >
            {side.image !== null ? (
                <img src={side.image} alt="" className="h-40 w-full object-cover" />
            ) : (
                // Same paper-stripe placeholder the detail screen uses. The pair works
                // without a photo; it just works better with one.
                <div className="paper-stripe h-40 w-full" />
            )}

            <p className="text-title text-ink p-4 font-serif">{side.caption}</p>
        </button>
    );
}
