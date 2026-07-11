import {
    AppHeader,
    ChoicePill,
    ContextStamp,
    EditorialLede,
    EmptyFeed,
    EndNote,
    EvidenceList,
    FacetLabels,
    MapAttribution,
    MapCanvasPlaceholder,
    MapPin,
    MetaRow,
    OpportunityCard,
    OpportunityCardPlaceholder,
    PaperPlaceholder,
    PASSO_TABS,
    PassoButton,
    ProgressSegments,
    SideRail,
    UrgencyHeader,
    WhyYou,
    Wordmark,
} from '@/components/passo';
import { useAppName } from '@/hooks/use-app-name';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { CONTEXT, EVIDENCE, LEDE, STANDARD_OPPORTUNITIES, URGENT_OPPORTUNITY, WHY_YOU } from './fixtures';

/**
 * The Passo kit gallery — /design (non-production only).
 *
 * This page is the acceptance test for E8: every component in the kit, rendered in both
 * themes, plus the three responsive frames. It exists to be *reviewed*, so each specimen
 * shows light and dark side by side rather than behind a toggle — the dark theme is a
 * first-class theme (DESIGN §2.2), and the cheapest way to keep it that way is to make
 * every regression visible without a click.
 *
 * "Passo" is the internal codename of the design system. The wordmark below comes from
 * APP_NAME, and that difference is the point (DESIGN §1).
 */

/** Renders its children twice: once on paper, once on night paper. */
function Specimen({ title, note, children, className }: { title: string; note?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
                <h2 className="text-ink text-title font-serif font-medium">{title}</h2>
                {note ? <p className="text-meta text-copy max-w-2xl">{note}</p> : null}
            </div>

            <div className="border-border bg-border rounded-block grid gap-px overflow-hidden border md:grid-cols-2">
                <div className={cn('bg-paper p-6', className)}>{children}</div>
                {/* Scoping `.dark` to a subtree works because every token resolves through an
                    inherited `--p-*` custom property — so both themes can coexist on one page. */}
                <div className={cn('dark bg-paper p-6', className)}>{children}</div>
            </div>
        </section>
    );
}

const COLOR_TOKENS = [
    'paper',
    'card',
    'ink',
    'body',
    'meta',
    'muted',
    'border',
    'border-soft',
    'border-strong',
    'terracotta',
    'on-terracotta',
    'urgent',
    'urgent-deep',
    'urgent-track',
    'olive',
    'map-bg',
    'map-road',
    'map-green',
];

const TYPE_SPECIMENS: { name: string; className: string; sample: string }[] = [
    { name: 'wordmark · Newsreader italic 500 · 22px', className: 'font-serif italic font-medium text-wordmark text-ink', sample: 'Wordmark' },
    {
        name: 'headline · Newsreader italic 500 · 24px',
        className: 'font-serif italic font-medium text-headline text-ink',
        sample: 'Nothing worth interrupting you for.',
    },
    {
        name: 'title-xl · Newsreader 500 · 26px',
        className: 'font-serif font-medium text-title-xl text-ink',
        sample: 'The gilded chapel at São Roque',
    },
    {
        name: 'title-lg · Newsreader 500 · 20px',
        className: 'font-serif font-medium text-title-lg text-ink',
        sample: 'The gilded chapel at São Roque',
    },
    { name: 'title · Newsreader 500 · 18px', className: 'font-serif font-medium text-title text-ink', sample: 'Last bake at Padaria São Bento' },
    { name: 'lede · Newsreader italic 400 · 15px', className: 'font-serif italic text-lede text-body', sample: 'One thing worth going for now.' },
    {
        name: 'copy-lg · Karla 400 · 14px',
        className: 'text-copy-lg text-body',
        sample: 'The west windows only reach the gold leaf in the last hour.',
    },
    { name: 'copy · Karla 400 · 13px', className: 'text-copy text-body', sample: 'The west windows only reach the gold leaf in the last hour.' },
    { name: 'micro · Karla 500 · 11px', className: 'text-micro font-medium text-meta', sample: '6 min walk · free' },
    {
        name: 'facet · Karla 500 · 10px · .12em',
        className: 'text-facet tracking-facet font-medium uppercase text-meta',
        sample: 'History · Architecture',
    },
    { name: 'gonow · Karla 700 · 10.5px · .18em', className: 'text-gonow tracking-gonow font-bold uppercase text-urgent-deep', sample: 'Go now' },
    { name: 'stamp · Karla 500 · 10px · .14em', className: 'text-stamp tracking-stamp font-medium uppercase text-meta', sample: 'Lisbon · 17:12' },
];

const FRAMES: { label: string; width: number; note: string }[] = [
    { label: '~400px · the phone', width: 400, note: 'The primary surface. Floating tab bar, single column.' },
    { label: '≥640px · centered', width: 640, note: 'The phone layout, centered. Never a half-stretched hybrid.' },
    { label: '≥1024px · rail + two-pane', width: 1024, note: 'Left rail replaces the tab bar; MAP pane joins the column.' },
];

type FrameState = 'feed' | 'empty' | 'loading';

function ResponsiveFrames() {
    const [state, setState] = useState<FrameState>('feed');

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <h2 className="text-ink text-title font-serif font-medium">The three frames</h2>
                <p className="text-meta text-copy max-w-2xl">
                    Real iframes, so each one has its own viewport and the breakpoints resolve honestly. Each width is shown on paper and on night
                    paper.
                </p>
            </div>

            <div className="flex flex-wrap gap-2">
                {(['feed', 'empty', 'loading'] as const).map((option) => (
                    <ChoicePill key={option} selected={state === option} onClick={() => setState(option)} className="capitalize">
                        {option}
                    </ChoicePill>
                ))}
            </div>

            <div className="flex flex-col gap-8">
                {(['light', 'dark'] as const).map((theme) => (
                    <div key={theme} className="flex flex-col gap-3">
                        <p className="text-meta text-facet tracking-facet font-medium uppercase">{theme === 'light' ? 'Paper' : 'Night paper'}</p>
                        <div className="flex gap-5 overflow-x-auto pb-3">
                            {FRAMES.map((frame) => (
                                <figure key={frame.width} className="flex shrink-0 flex-col gap-2">
                                    <iframe
                                        title={`${frame.label} — ${theme}`}
                                        src={`/design/frame?theme=${theme}&state=${state}`}
                                        width={frame.width}
                                        height={720}
                                        className="border-border rounded-block border"
                                    />
                                    <figcaption className="flex max-w-[26rem] flex-col gap-0.5">
                                        <span className="text-ink text-micro font-semibold">{frame.label}</span>
                                        <span className="text-meta text-micro">{frame.note}</span>
                                    </figcaption>
                                </figure>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

export default function Kit() {
    const appName = useAppName();
    const [selectedPill, setSelectedPill] = useState('45 min');
    const [step, setStep] = useState(4);

    return (
        <>
            <Head title="Passo kit" />

            <div className="bg-paper text-ink min-h-svh">
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-12 px-6 py-10">
                    <header className="flex flex-col gap-3">
                        <h1 className="text-ink text-headline font-serif font-medium italic">The Passo kit</h1>
                        <p className="text-body text-copy max-w-2xl">
                            Every component in <code className="text-micro">components/passo/</code>, on paper and on night paper. Passo is the
                            permanent internal codename of this design system; the wordmark you see below is{' '}
                            <strong className="font-semibold">{appName}</strong>, read from <code className="text-micro">APP_NAME</code> — it is never
                            hard-coded, so renaming the product stays a config change.
                        </p>
                        <p className="text-meta text-copy">
                            Not a product screen — this route does not exist in production.{' '}
                            <Link href="/attributions" className="underline [text-underline-offset:3px]">
                                Attribution &amp; licences
                            </Link>
                        </p>
                    </header>

                    <Specimen
                        title="Colour tokens"
                        note="Every token name exists in both themes. Colour means one thing — go now: terracotta is the primary action fill and ochre is urgency. Nothing else is allowed to be loud."
                    >
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {COLOR_TOKENS.map((token) => (
                                <div key={token} className="flex items-center gap-2.5">
                                    <span
                                        className="border-border-strong size-9 shrink-0 rounded-md border"
                                        style={{ background: `var(--p-${token})` }}
                                    />
                                    <span className="text-meta text-micro font-medium break-all">{token}</span>
                                </div>
                            ))}
                        </div>
                    </Specimen>

                    <Specimen
                        title="Type scale"
                        note="Newsreader carries the voice; Karla carries the practical. Authored in rem so OS text scaling works; form inputs never drop below 16px."
                    >
                        <div className="flex flex-col gap-5">
                            {TYPE_SPECIMENS.map((specimen) => (
                                <div key={specimen.name} className="flex flex-col gap-1">
                                    <span className="text-muted text-stamp tracking-stamp uppercase">{specimen.name}</span>
                                    <span className={specimen.className}>{specimen.sample}</span>
                                </div>
                            ))}

                            <div className="flex flex-col gap-1">
                                <span className="text-muted text-stamp tracking-stamp uppercase">form input · never under 16px</span>
                                <input
                                    type="text"
                                    defaultValue="Where are you starting from?"
                                    className="border-border-strong bg-card text-ink rounded-pill w-full border px-4 py-2"
                                />
                            </div>
                        </div>
                    </Specimen>

                    <Specimen
                        title="Shape, elevation, texture"
                        note="Radii, the three shadows, and the diagonal paper stripe that stands in for a photo — never a shimmer skeleton."
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-wrap items-end gap-4">
                                {[
                                    ['rounded-card', 'card · 10px'],
                                    ['rounded-block', 'block · 12px'],
                                    ['rounded-sheet', 'sheet · 14px'],
                                    ['rounded-pill', 'pill · 99px'],
                                ].map(([cls, label]) => (
                                    <div key={cls} className="flex flex-col items-center gap-1.5">
                                        <span className={cn('bg-card border-border size-16 border', cls)} />
                                        <span className="text-meta text-stamp">{label}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="flex flex-wrap items-end gap-4">
                                {[
                                    ['shadow-card', 'resting card'],
                                    ['shadow-urgent', 'go now'],
                                    ['shadow-sheet', 'floating sheet'],
                                ].map(([cls, label]) => (
                                    <div key={cls} className="flex flex-col items-center gap-1.5">
                                        <span className={cn('bg-card border-border rounded-card size-16 border', cls)} />
                                        <span className="text-meta text-stamp">{label}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="flex items-end gap-4">
                                <PaperPlaceholder className="h-24 w-40" />
                                <span className="bg-card border-border-strong rounded-pill stamp-rotate text-meta text-facet tracking-facet border border-dashed px-3 py-1 uppercase">
                                    Stamp · rotated once
                                </span>
                            </div>
                        </div>
                    </Specimen>

                    <Specimen
                        title="Buttons & pills"
                        note="The action vocabulary is closed: Take me · Keep / Remind me · Not for me. Never Book, Explore, Discover, View details."
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-wrap items-center gap-3">
                                <PassoButton variant="primary">Take me</PassoButton>
                                <PassoButton variant="secondary">Keep</PassoButton>
                                <PassoButton variant="text">Take me</PassoButton>
                                <PassoButton variant="quiet">Not for me</PassoButton>
                                <PassoButton variant="primary" disabled>
                                    Disabled
                                </PassoButton>
                            </div>

                            <div className="flex flex-col gap-2">
                                <span className="text-muted text-stamp tracking-stamp uppercase">
                                    Choice pills · selected is ink, never terracotta
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    {['45 min', '2 h', '3 h', 'All day'].map((option) => (
                                        <ChoicePill key={option} selected={selectedPill === option} onClick={() => setSelectedPill(option)}>
                                            {option}
                                        </ChoicePill>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </Specimen>

                    <Specimen
                        title="OpportunityCard"
                        note="The core object. Urgent variant carries the ring, the 1.5px ochre border, the shadow and the filled pill — urgency is never colour-only, and a feed shows at most one."
                    >
                        <div className="flex flex-col gap-3.5">
                            <OpportunityCard opportunity={URGENT_OPPORTUNITY} urgent />
                            {STANDARD_OPPORTUNITIES.map((opportunity, index) => (
                                <OpportunityCard key={opportunity.id} opportunity={opportunity} index={index + 1} />
                            ))}
                            <OpportunityCardPlaceholder />
                        </div>
                    </Specimen>

                    <Specimen
                        title="UrgencyHeader & the ring"
                        note="The ring never appears without its label — alone it reads as a loading spinner. It draws once on entry, then is static: it must never pulse or spin."
                    >
                        <div className="flex flex-col gap-4">
                            <UrgencyHeader window={{ remaining_minutes: 40, total_minutes: 55, note: '~40 min of light left' }} />
                            <UrgencyHeader window={{ remaining_minutes: 12, total_minutes: 90, note: '~12 min before the last tray' }} />
                            <UrgencyHeader window={{ remaining_minutes: 85, total_minutes: 90, note: 'the market runs until ~12:00' }} />
                        </div>
                    </Specimen>

                    <Specimen title="Chrome · AppHeader, Wordmark, ContextStamp">
                        <div className="flex flex-col gap-5">
                            <AppHeader context={CONTEXT} />
                            <div className="flex flex-wrap items-baseline gap-6">
                                <Wordmark />
                                <ContextStamp city="Aix-en-Provence" time="09:04" />
                                <FacetLabels facets={['food_drink', 'local_life', 'craft']} />
                                <MetaRow facts={[{ label: '6 min walk' }, { label: 'free' }]} />
                            </div>
                            <p className="text-meta text-copy">
                                TabBar and SideRail are viewport-driven by design, so they are reviewed live in the frames below — the bottom pill at
                                400 and 640, the rail at 1024. The rail also renders here, because this page is wide:
                            </p>
                            <div className="border-border rounded-block overflow-hidden border">
                                <SideRail active={PASSO_TABS[0].href} context={CONTEXT} onSelect={() => {}} className="h-72" />
                            </div>
                        </div>
                    </Specimen>

                    <Specimen
                        title="EditorialLede, WhyYou, EvidenceList, EndNote"
                        note="The voice layer. Evidence is source transparency, not a debug panel: every claim shows where it came from and when it was checked."
                    >
                        <div className="flex flex-col gap-6">
                            <EditorialLede>{LEDE}</EditorialLede>
                            <WhyYou>{WHY_YOU}</WhyYou>
                            <EvidenceList items={EVIDENCE} />
                            <EndNote>That&rsquo;s all for now.</EndNote>
                        </div>
                    </Specimen>

                    <Specimen
                        title="ProgressSegments"
                        note="Calibration progress (9 pairs + 2 practical questions). Done and current are terracotta."
                    >
                        <div className="flex flex-col gap-4">
                            <ProgressSegments total={9} current={step} />
                            <div className="flex items-center gap-3">
                                <PassoButton variant="secondary" density="compact" onClick={() => setStep((n) => Math.max(0, n - 1))}>
                                    Back
                                </PassoButton>
                                <PassoButton variant="secondary" density="compact" onClick={() => setStep((n) => Math.min(9, n + 1))}>
                                    Next
                                </PassoButton>
                                <span className="text-meta text-micro">{step} of 9</span>
                            </div>
                        </div>
                    </Specimen>

                    <Specimen
                        title="EmptyFeed"
                        note="Silence is a first-class screen. Confident, never apologetic — no 'No results found', no retry button."
                    >
                        <EmptyFeed nextLikelyMoment="around 17:00" />
                    </Specimen>

                    <Specimen
                        title="MapPin set & the paper map"
                        note="GO NOW pin, standard pin, and the olive-ringed 'you'. The canvas here is a styled placeholder — the real map is MapLibre + vector tiles, lazy-loaded on first MAP open so it never enters the feed's critical path."
                    >
                        <div className="flex flex-col gap-5">
                            <div className="flex flex-wrap items-center gap-6">
                                <MapPin kind="urgent" label="Go now" />
                                <MapPin kind="standard" label="last bake" />
                                <MapPin kind="you" />
                            </div>

                            <MapCanvasPlaceholder className="relative h-56">
                                <span className="absolute top-[18%] left-[24%]">
                                    <MapPin kind="urgent" label="Go now" />
                                </span>
                                <span className="absolute top-[55%] left-[16%]">
                                    <MapPin kind="standard" label="last bake" />
                                </span>
                                <span className="absolute top-[70%] left-[62%]">
                                    <MapPin kind="you" />
                                </span>
                                <MapAttribution />
                            </MapCanvasPlaceholder>
                        </div>
                    </Specimen>

                    <ResponsiveFrames />

                    <footer className="border-border-soft flex flex-col gap-2 border-t pt-8">
                        <EndNote>Quiet until morning, unless something can&rsquo;t wait.</EndNote>
                    </footer>
                </div>
            </div>
        </>
    );
}
