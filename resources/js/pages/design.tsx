import {
    AppHeader,
    ChoicePill,
    EditorialLede,
    EmptyFeed,
    EvidenceList,
    GoNowPin,
    OpportunityCard,
    PlacePin,
    PlaceSearch,
    PrimaryPill,
    ProgressSegments,
    QuietAction,
    SecondaryPill,
    SectionLabel,
    TabBar,
    TextAction,
    VisitPromptCard,
    WhyYou,
    YouMarker,
} from '@/components/app';
import { useAppearance } from '@/hooks/use-appearance';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

const TABS = [
    { label: 'Now', href: '#', active: true },
    { label: 'Map', href: '#' },
    { label: 'Kept', href: '#' },
    { label: 'Journal', href: '#' },
];

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="space-y-4">
            <h2 className="text-facet text-quiet font-medium tracking-[.14em] uppercase">{title}</h2>
            {children}
        </section>
    );
}

/** E8 demo page: every design-system component with mock data, both themes, three widths. */
export default function Design() {
    const { updateAppearance } = useAppearance();
    const [choice, setChoice] = useState('20 min');

    return (
        <div className="bg-paper min-h-screen pb-28">
            <Head title="Design system" />
            <TabBar tabs={TABS} />

            <div className="mx-auto max-w-md space-y-10 px-5 py-8">
                <AppHeader contextStamp="Lisbon · 17:12" />
                <EditorialLede>One thing worth going for now. Two keep until tomorrow.</EditorialLede>

                <Section title="Theme">
                    <div className="flex gap-2">
                        <ChoicePill onClick={() => updateAppearance('light')}>Light</ChoicePill>
                        <ChoicePill onClick={() => updateAppearance('dark')}>Dark</ChoicePill>
                        <ChoicePill onClick={() => updateAppearance('system')}>System</ChoicePill>
                    </div>
                </Section>

                <Section title="Opportunity cards">
                    <OpportunityCard
                        title="The gilded chapel at São Roque"
                        summary="Low sun reaches the side chapel for the next ~40 minutes. Use the side entrance — no queue right now."
                        meta="6 min walk · free"
                        urgency={{ remaining: 0.55, note: '~40 min of light left' }}
                    />
                    <OpportunityCard
                        title="Last bake at Padaria São Bento"
                        summary="The 17:30 batch of pão alentejano usually sells out in twenty minutes."
                        facets={['Food', 'Local life']}
                        meta="4 min walk · €"
                    />
                </Section>

                <Section title="Buttons">
                    <div className="flex flex-wrap items-center gap-4">
                        <PrimaryPill>Take me there</PrimaryPill>
                        <SecondaryPill>Keep</SecondaryPill>
                        <TextAction>Take me</TextAction>
                        <QuietAction>Not for me</QuietAction>
                    </div>
                </Section>

                <Section title="Calibration">
                    <ProgressSegments total={9} current={4} />
                    <div className="flex gap-2">
                        {['10 min', '20 min', '40+'].map((label) => (
                            <ChoicePill key={label} selected={choice === label} onClick={() => setChoice(label)}>
                                {label}
                            </ChoicePill>
                        ))}
                    </div>
                </Section>

                <Section title="Detail blocks">
                    <WhyYou>
                        You stayed twenty minutes with the azulejos at the cathedral yesterday, and you've said yes to quiet interiors twice this
                        trip.
                    </WhyYou>
                    <EvidenceList
                        items={[
                            { text: 'Open until 19:00 — parish site, checked 16:50' },
                            { text: 'Queue: short — last three visitor reports' },
                            { text: 'Sunset 18:04 — west light until ~17:50' },
                        ]}
                    />
                </Section>

                <Section title="Map pins">
                    <div className="rounded-photo bg-map-bg flex items-end justify-around p-6">
                        <GoNowPin label="Go now · 40 min" />
                        <PlacePin label="padaria" />
                        <YouMarker />
                    </div>
                </Section>

                <Section title="Placeholder">
                    <div className="paper-stripe rounded-card border-border h-24 border" />
                </Section>

                <Section title="Empty feed">
                    <div className="rounded-card border-border bg-card border">
                        <EmptyFeed
                            headline="Nothing worth interrupting you for."
                            body="You're in a good spot — the afternoon is yours. I'm keeping an eye on the light, the queues, and the 17:30 bake."
                            nextMoment="Next likely moment · around 17:00"
                        />
                    </div>
                </Section>

                <Section title="Section label">
                    <SectionLabel>history · architecture</SectionLabel>
                </Section>

                <Section title="Place search — the manual start point (S2)">
                    <PlaceSearch onChoose={() => {}} />
                </Section>

                <Section title="Were you there? (S4)">
                    <VisitPromptCard placeName="Färgfabriken" onWasThere={() => {}} onDidntGo={() => {}} />
                </Section>
            </div>
        </div>
    );
}
