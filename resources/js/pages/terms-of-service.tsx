import LegalLayout, { LegalSection } from '@/layouts/legal-layout';
import { Link } from '@inertiajs/react';

interface TermsOfServiceProps {
    contactEmail: string;
    updated: string;
}

/**
 * Terms of service (docs/legal/ROPA.md §3, finding B4).
 *
 * NOT boilerplate, and not optional. Six rows of the privacy notice give "performance
 * of a contract" (GDPR Art. 6(1)(b)) as the lawful basis for processing location — and
 * that basis requires a contract to exist. This page IS the contract. Without it, the
 * basis for the single most sensitive thing the app does collapses to nothing.
 *
 * Which is why the tone is not the tone of a EULA. It is short, it is readable, and it
 * says the two things that actually matter for a product that sends people walking
 * across a city on the strength of a machine's guess: the suggestions can be wrong, and
 * you are the one who decides whether to go.
 */
export default function TermsOfService({ contactEmail, updated }: TermsOfServiceProps) {
    return (
        <LegalLayout
            title="Terms"
            lede="The agreement between you and me. It is short, because most of what a document like this usually contains is there to protect someone who has something to hide."
            updated={updated}
        >
            <LegalSection heading="Who you're agreeing with">
                <p>
                    This service is provided by <strong>Mats Bergsten</strong>, a private individual in Sweden — not a company. By creating an account
                    you're agreeing to what's on this page.
                </p>
                <p>
                    <a href={`mailto:${contactEmail}`} className="text-ink underline underline-offset-[3px]">
                        {contactEmail}
                    </a>
                </p>
            </LegalSection>

            <LegalSection heading="What this is, and what it isn't">
                <p>
                    This is <strong>unfinished software in a private pilot</strong>. It is free, there is nothing to pay, and there is no service level
                    of any kind. It may break, lose data, be taken down for a day, or be discontinued. If you're relying on it for something that
                    matters, don't.
                </p>
                <p>
                    It suggests places you might enjoy, near where you are, in the time you have. That's all it does. It is not a navigation system, a
                    safety tool, or a source of truth.
                </p>
            </LegalSection>

            <LegalSection heading="The suggestions can be wrong — and this is the part to actually read">
                <p>
                    The app draws on open data, on public sources, and on a language model that writes the descriptions from that evidence. Every one
                    of those can be out of date or simply mistaken.
                </p>
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>Opening hours may be wrong.</strong> I check them where I can, and I tell you when I couldn't.
                    </li>
                    <li>
                        <strong>A place may have closed, moved, or never have been there.</strong>
                    </li>
                    <li>
                        <strong>Walking times are estimates.</strong> They don't know about the roadworks, the hill, or your knee.
                    </li>
                    <li>
                        <strong>The descriptions are generated.</strong> They're written from stored evidence and never invented from nothing — but a
                        machine wrote them, and machines are confidently wrong sometimes.
                    </li>
                </ul>
                <p>
                    <strong>You decide whether to go.</strong> The app can tell you there's a viewpoint twenty minutes away; it cannot tell you whether
                    the path is icy, whether the neighbourhood is somewhere you want to be after dark, or whether you're well enough for the walk. Use
                    your judgement, and don't outsource your safety to a suggestion engine. If something looks wrong when you get there, it probably
                    is.
                </p>
            </LegalSection>

            <LegalSection heading="Your account">
                <p>
                    Registration is currently invite-only. Keep your password to yourself, and tell me if you think someone else has got into your
                    account.
                </p>
                <p>
                    Don't use the service to break the law, to scrape or bulk-extract the data behind it, to attack the infrastructure, or to try to
                    identify other users. I can suspend or remove an account that does — though with a pilot this size, I'd email you first.
                </p>
                <p>
                    <strong>You can leave whenever you like.</strong> Settings → Privacy → Delete my account removes everything. No exit interview, no
                    retention offer.
                </p>
            </LegalSection>

            <LegalSection heading="Your data">
                <p>
                    What I collect, why, who else sees it, and how long I keep it is set out in the{' '}
                    <Link href="/privacy-policy" className="text-ink underline underline-offset-[3px]">
                        privacy notice
                    </Link>
                    , and it's worth reading — particularly the part about what the taste profile can end up inferring about you.
                </p>
                <p>
                    Your travel history is yours. You can export it in full, and delete it in full, at any time.
                </p>
            </LegalSection>

            <LegalSection heading="Whose content is whose">
                <p>
                    The places, geometry and map data come from open projects — OpenStreetMap and others — and are used under their licences, which are
                    credited on the{' '}
                    <Link href="/licenses" className="text-ink underline underline-offset-[3px]">
                        data &amp; licenses
                    </Link>{' '}
                    page. Their terms travel with that data.
                </p>
                <p>The app itself, its writing, and its curated content are mine. Anything you write — a trip name, a note — stays yours.</p>
            </LegalSection>

            <LegalSection heading="What I'm liable for">
                <p>
                    The service is provided <strong>as is</strong>, with no warranty that it will be accurate, available, or fit for anything in
                    particular. To the extent the law allows, I'm not liable for loss you suffer from relying on a suggestion — a wasted walk, a closed
                    door, a missed train.
                </p>
                <p>
                    <strong>But I'm not going to pretend that clause does more than it does.</strong> Under Swedish and EU law you have rights that a
                    document like this cannot sign away, and nothing here limits my liability for death or personal injury caused by my negligence, for
                    fraud, or for anything else the law says I can't exclude. If a court decides part of this page is unenforceable, the rest still
                    stands.
                </p>
            </LegalSection>

            <LegalSection heading="Changes, and endings">
                <p>
                    I may change these terms — if I change anything that matters, I'll tell you rather than quietly reissuing the page. If you don't
                    like the change, delete your account; that's what it's there for.
                </p>
                <p>
                    This is a pilot, and pilots end. If the service shuts down, I'll give you notice and a chance to export everything before it goes.
                </p>
            </LegalSection>

            <LegalSection heading="Which law, and which court">
                <p>
                    Swedish law governs this agreement, and Swedish courts have jurisdiction. If you're a consumer resident elsewhere in the EU, this
                    doesn't deprive you of the protection of your own country's mandatory consumer law, or of your right to bring a claim there.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
