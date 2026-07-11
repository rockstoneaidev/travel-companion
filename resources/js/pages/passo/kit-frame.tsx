import {
    EditorialLede,
    EmptyFeed,
    EndNote,
    MapAttribution,
    MapCanvasPlaceholder,
    MapPin,
    OpportunityCard,
    OpportunityCardPlaceholder,
    PassoShell,
} from '@/components/passo';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { CONTEXT, LEDE, STANDARD_OPPORTUNITIES, URGENT_OPPORTUNITY } from './fixtures';

/**
 * The kit rendered as a real screen, for the gallery's responsive frames.
 *
 * The gallery embeds this in 400 / 640 / 1024px iframes. That indirection is the point:
 * Tailwind's breakpoints answer to the *viewport*, not a container, so a 400px-wide div
 * on a desktop page would still be resolving `lg:` rules and would quietly lie about the
 * mobile layout. An iframe has its own viewport, so what you see is what a phone gets.
 *
 * Query params (set by the gallery): `?theme=dark`, `?state=empty|loading`.
 */
type FrameState = 'feed' | 'empty' | 'loading';

function useFrameParams() {
    // Read once, before paint, so the frame never flashes the wrong theme.
    const [params] = useState(() => {
        const search = new URLSearchParams(window.location.search);
        const theme = search.get('theme') === 'dark' ? 'dark' : 'light';

        document.documentElement.classList.toggle('dark', theme === 'dark');

        const requested = search.get('state');
        const state: FrameState = requested === 'empty' || requested === 'loading' ? requested : 'feed';

        return { theme, state };
    });

    return params;
}

export default function KitFrame() {
    const { state } = useFrameParams();

    const feed = (
        <div className="flex flex-col gap-6">
            <EditorialLede>{LEDE}</EditorialLede>

            <div className="flex flex-col gap-3.5">
                <OpportunityCard opportunity={URGENT_OPPORTUNITY} urgent index={0} />
                {STANDARD_OPPORTUNITIES.map((opportunity, index) => (
                    <OpportunityCard key={opportunity.id} opportunity={opportunity} index={index + 1} />
                ))}
                {state === 'loading' ? <OpportunityCardPlaceholder /> : null}
            </div>

            {state === 'loading' ? <EndNote>Still looking.</EndNote> : <EndNote>That&rsquo;s all for now.</EndNote>}
        </div>
    );

    return (
        <>
            <Head title="Passo frame" />

            <PassoShell
                active="/"
                context={CONTEXT}
                onSelect={() => {
                    /* Inert: the product routes are E9's. The gallery only reviews the chrome. */
                }}
                aside={
                    <div className="h-full p-8">
                        {/* The two-pane MAP at ≥1024px (DESIGN §4). */}
                        <MapCanvasPlaceholder className="relative h-full min-h-[24rem]">
                            <span className="absolute top-[26%] left-[30%]">
                                <MapPin kind="urgent" label="Go now" />
                            </span>
                            <span className="absolute top-[58%] left-[22%]">
                                <MapPin kind="standard" label="last bake" />
                            </span>
                            <span className="absolute top-[72%] left-[58%]">
                                <MapPin kind="you" />
                            </span>
                            <MapAttribution />
                        </MapCanvasPlaceholder>
                    </div>
                }
            >
                {state === 'empty' ? <EmptyFeed nextLikelyMoment="around 17:00" /> : feed}
            </PassoShell>
        </>
    );
}
