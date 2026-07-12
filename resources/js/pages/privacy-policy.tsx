import LegalLayout, { LegalSection } from '@/layouts/legal-layout';
import { Link } from '@inertiajs/react';

interface PrivacyPolicyProps {
    /** config('privacy.raw_location_retention_days') — never hard-code the number here. */
    retentionDays: number;
    /** The controller's contact address. */
    contactEmail: string;
    updated: string;
}

/**
 * The Art. 13–14 privacy notice (docs/legal/PRIVACY-NOTICE.md).
 *
 * The retention figure comes from the BACKEND, from config('privacy.*), because a
 * notice that states "30 days" while the retention job runs on 60 is a false statement
 * to a data subject. The number is a promise, so it is read from the thing that keeps it.
 *
 * The uncomfortable section (§ "What this can reveal about you") sits high on the page
 * and in the same type as everything else, for the same reason the consent checkbox does
 * (DPIA §3.2): a disclosure nobody reads is not a disclosure.
 */
export default function PrivacyPolicy({ retentionDays, contactEmail, updated }: PrivacyPolicyProps) {
    return (
        <LegalLayout
            title="Privacy"
            lede="I collect where you are, because I can't suggest what's near you otherwise. Here is exactly what that means, what I keep, and what I destroy."
            updated={updated}
        >
            <LegalSection heading="The short version">
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>I collect your location, but only while an explore session is open and only when the app is in front of you. Never in the background.</li>
                    <li>
                        I keep your exact coordinates for <strong>{retentionDays} days</strong>, then destroy them and keep only a roughly 0.7 km² grid
                        cell.
                    </li>
                    <li>You can declare a home zone. Inside it I store nothing precise, learn nothing, and suggest nothing.</li>
                    <li>I build a picture of your taste only if you say yes — and I delete it the moment you say no.</li>
                    <li>Your position goes to Google to work out walking times, and to a weather service. It goes without your name attached.</li>
                    <li>You can export everything, or delete everything, from Settings.</li>
                </ul>
            </LegalSection>

            <LegalSection heading="Who is responsible">
                <p>
                    <strong>Mats Bergsten</strong> — currently a person, not a company. There is no support desk; there is an inbox, and I read it.
                </p>
                <p>
                    <a href={`mailto:${contactEmail}`} className="text-ink underline underline-offset-[3px]">
                        {contactEmail}
                    </a>
                </p>
                <p>There is no Data Protection Officer, and at this size one isn't required. If that changes, this page changes.</p>
            </LegalSection>

            <LegalSection heading="What I collect, and what allows me to">
                <p>
                    <strong>Your name and email</strong>, so you have an account you can log back into. If you sign in with Google, I also receive your
                    Google ID, name and profile picture. I need this to run the service you signed up for.
                </p>
                <p>
                    <strong>Your precise location</strong>, while an explore session is open. This is not optional: a recommendation engine that
                    doesn't know where you are cannot recommend anything. I need it to provide the service.
                </p>
                <p>
                    <strong>Your situation</strong> — how long you have, whether you're walking, what the weather is doing, your phone's battery level
                    — so I can suggest things you can actually reach and do before the light goes.
                </p>
                <p>
                    <strong>What I suggested and why</strong>, including every score behind it, so the app can answer "why did I get this?". And{' '}
                    <strong>what you did about it</strong> — accepted, kept, dismissed, visited — so I stop suggesting things you keep ignoring.
                </p>
                <p>
                    <strong>Your taste profile</strong> — what I've inferred you like. <strong>Only with your consent</strong>, which you can withdraw
                    at any time.
                </p>
                <p>
                    <strong>Technical and security data</strong> — logins, errors, performance — to keep the service running and not get broken into.
                    That one rests on my legitimate interest in a service that works.
                </p>
                <p>
                    You're never obliged to give me any of this. The honest consequence: without location there is no product, and without the taste
                    profile there is a product that doesn't know you. Both are supported outcomes. Neither is punished.
                </p>
            </LegalSection>

            <LegalSection heading="What this can reveal about you">
                <p>
                    This is the most sensitive thing the app does, so I'd rather say it here than bury it.
                </p>
                <p>
                    <strong>The taste profile can end up revealing things you never told me.</strong> It learns from the kinds of places you choose. If
                    you keep choosing churches, chapels or cathedrals, it accumulates a high weight for religious and spiritual places — and that
                    weight is, in substance, a statement about your religious beliefs, inferred from your behaviour. The same mechanism could reach
                    your health, if you repeatedly went somewhere for it.
                </p>
                <p>
                    European law treats data about religious belief and health as special category data, and it may not be processed without your{' '}
                    <strong>explicit consent</strong>. That's why the box on the welcome screen starts unticked, why the button stays greyed out until
                    you tick it, and why it says what it says.
                </p>
                <p>
                    <strong>If you turn it off, I delete the profile.</strong> Not "stop updating it" — delete it. Keeping a guess about your beliefs
                    after you've withdrawn permission to make it would be exactly the thing you'd be right to be angry about.
                </p>
            </LegalSection>

            <LegalSection heading="Who else sees your data">
                <p>I use a small number of outside services. None of them are ever told who you are — no name, no email, no account ID, no cookie.</p>
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>Hetzner</strong> (Germany) — hosting. It holds everything, because it is the database.
                    </li>
                    <li>
                        <strong>Google Routes</strong> (USA) — receives <strong>your exact coordinates</strong> and a place you might walk to, to work
                        out how long it takes.
                    </li>
                    <li>
                        <strong>Google Places</strong> (USA) — receives a <em>place's</em> name and coordinates, to check whether it's actually open.
                        Nothing about you.
                    </li>
                    <li>
                        <strong>Google Gemini</strong> (USA) — the AI that writes the descriptions. It receives facts about a place, the time of day,
                        how you're travelling and which city you're in. No identity, no profile, no coordinates.
                    </li>
                    <li>
                        <strong>A weather service</strong> (Germany) — receives coordinates, to tell me if it's about to rain on you.
                    </li>
                    <li>
                        <strong>Resend</strong> (USA) — receives your email address when I send you a password reset or a verification link.
                    </li>
                </ul>
                <p>
                    Transfers to the USA rely on the EU–US Data Privacy Framework, backed by standard contractual clauses.
                </p>
                <p>
                    <strong>The sharpest one, said out loud:</strong> to tell you something is a seven-minute walk rather than guessing, I have to send
                    where you're standing to Google. That's a real transfer of a real coordinate to a US company. It goes anonymously — Google receives
                    a point on a map, not a person — but it goes, and you should know that it does.
                </p>
                <p>
                    <strong>What never leaves:</strong> your name, your email, your taste profile, your feedback history, and your home zone.
                </p>
            </LegalSection>

            <LegalSection heading="How long I keep things">
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>Your exact coordinates: {retentionDays} days.</strong> Then destroyed — permanently, not archived. What survives is a
                        roughly 0.7 km² grid cell: enough to learn that you like waterfront viewpoints, not enough to find your door.
                    </li>
                    <li>
                        <strong>Anything inside your home zone: never stored precisely at all.</strong> There's nothing to delete, because it was never
                        written down.
                    </li>
                    <li>
                        <strong>What I suggested and why:</strong> kept, because it's how the app explains itself. Its coordinates are stripped on the
                        same {retentionDays}-day clock.
                    </li>
                    <li>
                        <strong>Your taste profile:</strong> until you reset it, withdraw consent, or delete your account.
                    </li>
                    <li>
                        <strong>Your feedback history and account:</strong> until you delete your account.
                    </li>
                    <li>
                        <strong>Opening hours from Google:</strong> ten minutes, in memory. Never written to the database.
                    </li>
                </ul>
                <p>
                    If you turn on <strong>research consent</strong> (off by default, in Settings), your recommendation traces keep their exact
                    coordinates past {retentionDays} days, so they can be used to test whether changes to the ranking make it better or worse. That's
                    the whole of what it buys, and turning it off puts you back on the {retentionDays}-day clock.
                </p>
            </LegalSection>

            <LegalSection heading="What you can do">
                <p>
                    All of these are in{' '}
                    <Link href="/settings/privacy" className="text-ink underline underline-offset-[3px]">
                        Settings → Privacy
                    </Link>
                    , and all of them work today.
                </p>
                <ul className="list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>See everything I hold.</strong> One button, and you get a file with all of it — your trips, everything I showed you and
                        why, everything you told me back, and the taste profile itself. Not a summary. The actual thing that decides what you see.
                    </li>
                    <li>
                        <strong>Delete your account</strong> and everything attached to it, permanently. The feedback history goes too. It's valuable to
                        me and it's yours, and "delete my account" is not a negotiation.
                    </li>
                    <li>
                        <strong>Delete a trip's location history</strong> without deleting the trip.
                    </li>
                    <li>
                        <strong>Reset the taste profile</strong> — forget what I concluded about you, keep what you did.
                    </li>
                    <li>
                        <strong>Withdraw profiling consent</strong>, which also deletes the profile. One click, no password, no "are you sure you want
                        to lose your personalised experience".
                    </li>
                    <li>
                        <strong>Declare a home zone</strong> — a point and a radius. Inside it, I don't learn, don't suggest, and don't store a
                        coordinate. Ever.
                    </li>
                    <li>
                        <strong>Correct anything that's wrong</strong>, object to processing based on my legitimate interests, or ask me to restrict
                        what I do with your data — email me.
                    </li>
                </ul>
                <p>
                    <strong>And you can complain about me.</strong> If you think I've handled your data badly, complain to the Swedish supervisory
                    authority,{' '}
                    <a href="https://www.imy.se" rel="noopener" className="text-ink underline underline-offset-[3px]">
                        IMY
                    </a>
                    , or to the authority where you live. You don't have to ask me first, and I'd rather you had the option than not.
                </p>
            </LegalSection>

            <LegalSection heading="Decisions made about you by machine">
                <p>
                    The app scores places against a model of your taste and shows you the top few. That's automated, and it's the entire point. It
                    isn't the kind of automated decision-making the law restricts, because nothing here has a legal or similarly significant effect on
                    you: it suggests a viewpoint, you ignore it, nothing happens.
                </p>
                <p>
                    You can still ask it <em>why</em>. Every card can tell you what it weighed, because every recommendation stores its full reasoning.
                    That's not there because the law demands it. It's there because a companion that can't say why it suggested something doesn't
                    deserve to be trusted.
                </p>
            </LegalSection>

            <LegalSection heading="Children">
                <p>This app isn't for children, and I don't knowingly collect their data. Registration is currently invite-only.</p>
            </LegalSection>

            <LegalSection heading="Changes to this page">
                <p>
                    If I change what I collect, who gets it, or how long I keep it, I'll update this page and say so. If the change means the taste
                    profile starts inferring something new, <strong>your existing consent stops counting and I'll ask you again</strong> — that's built
                    into how consent is stored, not a promise I'm making.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
