import { EditorialLede } from '@/components/passo';
import { useAppName } from '@/hooks/use-app-name';
import { Head, Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Attribution & licences.
 *
 * This screen is a legal obligation, not decoration. Recommendations are ODbL "Produced
 * Works" (ODBL-REVIEW §4.3), and §6.6 of that review names exactly what must be cited
 * here: OpenStreetMap, Overture, Etalab/Licence Ouverte, and per-source evidence
 * citations. It doubles as the source-transparency surface the product promises anyway
 * (PRD §16) — the companion earns trust by showing where its facts come from.
 *
 * Reachable without signing in: attribution is owed to whoever sees the data.
 */

interface Credit {
    source: string;
    licence: string;
    href: string;
    what: string;
}

/** The geo-core: only ODbL-compatible open data may live here (ODBL-REVIEW §6). */
const GEO_CORE: Credit[] = [
    {
        source: 'OpenStreetMap',
        licence: 'ODbL 1.0',
        href: 'https://www.openstreetmap.org/copyright',
        what: 'Names, geometry and categories for most places — including the viewpoints, fountains, ruins and shelters nothing else lists.',
    },
    {
        source: 'Overture Maps Foundation',
        licence: 'CDLA-Permissive 2.0',
        href: 'https://docs.overturemaps.org/attribution/',
        what: 'The open global places base the canonical table is built on.',
    },
    {
        source: 'Wikidata',
        licence: 'CC0 1.0',
        href: 'https://www.wikidata.org/wiki/Wikidata:Licensing',
        what: 'Structured facts and the relationships between places.',
    },
    {
        source: 'French public open data (Etalab)',
        licence: 'Licence Ouverte 2.0',
        href: 'https://www.etalab.gouv.fr/licence-ouverte-open-licence/',
        what: 'Tourism and heritage records in the French launch region.',
    },
];

/** Evidence and imagery: kept out of the geo-core, attributed per source. */
const CONTENT: Credit[] = [
    {
        source: 'Wikipedia',
        licence: 'CC BY-SA',
        href: 'https://en.wikipedia.org/wiki/Wikipedia:Copyrights',
        what: 'Historical and architectural background quoted as evidence.',
    },
    {
        source: 'Wikivoyage',
        licence: 'CC BY-SA',
        href: 'https://en.wikivoyage.org/wiki/Wikivoyage:Copyrights',
        what: 'Travel-facing descriptions quoted as evidence.',
    },
    {
        source: 'Wikimedia Commons',
        licence: 'Per-file (mixed CC)',
        href: 'https://commons.wikimedia.org/wiki/Commons:Licensing',
        what: 'Photography. Each image carries its own credit where it is shown.',
    },
];

/** The map: tiles carry their own attribution line on the map itself (SCREENS S3). */
const MAP: Credit[] = [
    {
        source: 'OpenStreetMap contributors',
        licence: 'ODbL 1.0',
        href: 'https://www.openstreetmap.org/copyright',
        what: 'The data behind every map tile in this app.',
    },
    {
        source: 'OpenFreeMap',
        licence: 'Free vector tiles',
        href: 'https://openfreemap.org/',
        what: 'Serves the vector tiles the paper map style is drawn from.',
    },
];

/** Typefaces — self-hosted, so the app asks nothing of a font CDN at runtime. */
const FONTS: Credit[] = [
    {
        source: 'Newsreader — Production Type',
        licence: 'SIL Open Font License 1.1',
        href: 'https://fonts.google.com/specimen/Newsreader/about',
        what: 'The serif voice: the wordmark, titles, and everything the companion says in the first person.',
    },
    {
        source: 'Karla — Jonny Pinhorn',
        licence: 'SIL Open Font License 1.1',
        href: 'https://fonts.google.com/specimen/Karla/about',
        what: 'The practical sans: metadata, labels, buttons.',
    },
];

function CreditGroup({ title, note, credits }: { title: string; note?: ReactNode; credits: Credit[] }) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-meta text-facet tracking-facet font-medium uppercase">{title}</h2>
            {note ? <p className="text-body text-copy">{note}</p> : null}

            <ul className="border-border bg-card rounded-card divide-border-soft divide-y border">
                {credits.map((credit) => (
                    <li key={credit.source} className="flex flex-col gap-1 p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <a
                                href={credit.href}
                                target="_blank"
                                rel="noreferrer"
                                className="text-ink text-title font-serif font-medium underline decoration-1 [text-underline-offset:3px]"
                            >
                                {credit.source}
                            </a>
                            <span className="text-meta text-micro font-medium">{credit.licence}</span>
                        </div>
                        <p className="text-body text-copy">{credit.what}</p>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function Attributions() {
    const appName = useAppName();

    return (
        <>
            <Head title="Attribution & licences" />

            <div className="bg-paper text-ink min-h-svh">
                <div className="mx-auto flex w-full max-w-md flex-col gap-8 px-5 py-10 lg:max-w-2xl">
                    <header className="flex flex-col gap-3">
                        <h1 className="text-ink text-headline font-serif font-medium italic">Where this comes from.</h1>
                        <EditorialLede>
                            {appName} is built on open data and open type. Everything below is someone else&rsquo;s work that I rely on, and saying so
                            is part of the deal.
                        </EditorialLede>
                    </header>

                    <CreditGroup
                        title="Places & geography"
                        note={
                            <>
                                The place database is a derivative of OpenStreetMap and is licensed under the{' '}
                                <a
                                    href="https://opendatacommons.org/licenses/odbl/1-0/"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="underline [text-underline-offset:3px]"
                                >
                                    Open Database License 1.0
                                </a>
                                . It contains only open data, and it is offered on request &mdash; the proprietary parts of this product (what I
                                write, what I score, what I learn about you) are kept out of it by design.
                            </>
                        }
                        credits={GEO_CORE}
                    />

                    <CreditGroup
                        title="Evidence & imagery"
                        note="Every claim shown on an opportunity carries its source and the time it was checked. These are the sources that back them."
                        credits={CONTENT}
                    />

                    <CreditGroup title="The map" credits={MAP} />

                    <CreditGroup title="Typefaces" credits={FONTS} />

                    <section className="flex flex-col gap-3">
                        <h2 className="text-meta text-facet tracking-facet font-medium uppercase">What I don&rsquo;t keep</h2>
                        <p className="text-body text-copy">
                            Some facts &mdash; opening hours, current closures &mdash; are checked against Google Places at the moment I need them,
                            and are never written into the place database. I store the identifier and nothing else.
                        </p>
                    </section>

                    <footer className="border-border-soft flex flex-col gap-4 border-t pt-6">
                        <p className="text-muted text-copy-lg text-center font-serif italic">
                            Open data is why I can tell you about the fountain no one photographed.
                        </p>
                        <Link href="/" className="text-meta text-btn-sm hover:text-ink text-center font-medium underline [text-underline-offset:3px]">
                            Back
                        </Link>
                    </footer>
                </div>
            </div>
        </>
    );
}
