import { PassoAppHeader } from '@/components/passo';
import { Head } from '@inertiajs/react';

interface Credit {
    name: string;
    license: string;
    note: string;
    href: string;
}

const DATA_CREDITS: Credit[] = [
    {
        name: 'OpenStreetMap contributors',
        license: 'ODbL 1.0',
        note: 'Place names, geometry, and categories in the canonical places database, and the base map.',
        href: 'https://www.openstreetmap.org/copyright',
    },
    {
        name: 'Overture Maps Foundation',
        license: 'ODbL / CDLA-Permissive 2.0',
        note: 'Conflated place data in the canonical places database.',
        href: 'https://overturemaps.org/',
    },
    {
        name: 'Wikidata',
        license: 'CC0 1.0',
        note: 'Structured knowledge: identity links, historical references, story context.',
        href: 'https://www.wikidata.org/',
    },
    {
        name: 'Wikimedia Commons',
        license: 'per-image (CC BY / CC BY-SA / PD)',
        note: 'Photographs, each attributed at the point of display.',
        href: 'https://commons.wikimedia.org/',
    },
];

const SOFTWARE_CREDITS: Credit[] = [
    {
        name: 'Newsreader',
        license: 'SIL Open Font License 1.1',
        note: 'Production Type — the serif voice of this app. Self-hosted.',
        href: 'https://github.com/productiontype/Newsreader',
    },
    {
        name: 'Karla',
        license: 'SIL Open Font License 1.1',
        note: 'Jonny Pinhorn — the practical sans of this app. Self-hosted.',
        href: 'https://github.com/googlefonts/karla',
    },
];

function CreditRow({ credit }: { credit: Credit }) {
    return (
        <li className="border-border-soft border-b py-3 last:border-b-0">
            <div className="flex items-baseline justify-between gap-3">
                <a
                    href={credit.href}
                    rel="license noopener"
                    className="text-body-detail text-ink font-serif font-medium underline underline-offset-[3px]"
                >
                    {credit.name}
                </a>
                <span className="text-facet text-meta font-medium tracking-[.12em] uppercase">{credit.license}</span>
            </div>
            <p className="text-body-card text-body mt-1">{credit.note}</p>
        </li>
    );
}

/** Attribution & licenses (E8): ODbL attribution is a legal requirement (ODBL-REVIEW §6). */
export default function Licenses() {
    return (
        <div className="bg-paper min-h-screen">
            <Head title="Data & licenses" />
            <div className="mx-auto max-w-md space-y-8 px-5 py-8">
                <PassoAppHeader />
                <h1 className="text-headline text-ink font-serif font-medium italic">Built on open data.</h1>
                <p className="text-lede text-body font-serif italic">
                    The places this app knows about come from open projects maintained by people who map the world for everyone. Their work deserves
                    its credit.
                </p>

                <section>
                    <h2 className="text-facet text-meta mb-2 font-medium tracking-[.14em] uppercase">Data</h2>
                    <ul>
                        {DATA_CREDITS.map((c) => (
                            <CreditRow key={c.name} credit={c} />
                        ))}
                    </ul>
                </section>

                <section>
                    <h2 className="text-facet text-meta mb-2 font-medium tracking-[.14em] uppercase">Type</h2>
                    <ul>
                        {SOFTWARE_CREDITS.map((c) => (
                            <CreditRow key={c.name} credit={c} />
                        ))}
                    </ul>
                </section>
            </div>
        </div>
    );
}
