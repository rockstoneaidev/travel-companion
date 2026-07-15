import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

/**
 * The front page, for guests (DESIGN §1, §6).
 *
 * A logged-in traveller never sees this — the `/` route redirects them to the app. So this
 * is pure first impression, and it has one job: make a stranger feel the difference between
 * this and a booking app before they've signed anything. It borrows the product's whole
 * design language — paper, ink, one warm accent, serif in the first person — rather than a
 * generic hero, because the medium IS the pitch: a quiet, editorial note, not a results list.
 *
 * Two rules from the design system are load-bearing here:
 *   - Ochre (`urgent`) means "go now" and NOTHING else, so the call-to-action is terracotta,
 *     never the accent (DESIGN §1.1.1). A landing page that spent the urgency colour on a
 *     sign-up button would have nothing left to say GO NOW with inside the app.
 *   - The wordmark is the shared `name` prop (APP_NAME), never a hard-coded string — the
 *     market name is provisional and the rename must stay a config change.
 */

const primary =
    'bg-terracotta text-on-terracotta inline-flex min-h-11 items-center justify-center rounded-full px-6 py-2.5 text-sm font-bold transition-opacity duration-150 ease-out hover:opacity-90';
const secondary =
    'border-border-strong text-ink hover:bg-secondary inline-flex min-h-11 items-center justify-center rounded-full border px-6 py-2.5 text-sm font-semibold transition-colors duration-150 ease-out';

/** The "watching, quietly" motif — the same dashed ring + dot the empty feed draws. */
function QuietRing() {
    return (
        <div className="border-border-strong relative size-16 rounded-full border border-dashed motion-safe:animate-[spin_9s_linear_infinite]">
            <div className="bg-olive absolute top-1/2 left-1/2 size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full" />
        </div>
    );
}

function Promise({ title, children }: { title: string; children: string }) {
    return (
        <div className="space-y-2">
            <h3 className="text-ink font-serif text-lg font-medium italic">{title}</h3>
            <p className="text-body text-body-detail leading-relaxed">{children}</p>
        </div>
    );
}

export default function Welcome() {
    const { name } = usePage<SharedData>().props;

    return (
        <>
            <Head title="A quieter kind of travel companion" />

            <div className="bg-paper text-ink flex min-h-screen flex-col">
                {/* Header — wordmark left, the two doors right. */}
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
                    <span className="text-wordmark text-ink font-serif font-medium lowercase italic">{name}</span>
                    <nav className="flex items-center gap-3">
                        <Link href={route('login')} className="text-meta hover:text-ink px-2 py-1 text-sm font-medium transition-colors">
                            Sign in
                        </Link>
                        <Link href={route('register')} className={secondary}>
                            Create account
                        </Link>
                    </nav>
                </header>

                {/* Hero. */}
                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center px-6 py-16">
                    <div className="max-w-2xl">
                        <QuietRing />

                        <h1 className="text-ink mt-8 font-serif text-4xl leading-[1.1] font-medium italic sm:text-5xl">
                            The things you’d have walked right past.
                        </h1>

                        <p className="text-body mt-6 max-w-xl text-lg leading-relaxed">
                            Not a list of places to search. A quiet note when something near you is genuinely worth your time — the market that closes
                            in twenty minutes, the light on the old church at six — and silence the rest of the time.
                        </p>

                        <div className="mt-10 flex flex-wrap items-center gap-3">
                            <Link href={route('register')} className={primary}>
                                Create your account
                            </Link>
                            <Link href={route('login')} className={secondary}>
                                Sign in
                            </Link>
                        </div>

                        <p className="text-muted mt-4 text-sm">The pilot is invite-only for now.</p>
                    </div>

                    {/* Three promises, in the companion's own voice. */}
                    <div className="border-border-soft mt-20 grid gap-10 border-t pt-12 sm:grid-cols-3">
                        <Promise title="Opportunities, not places.">
                            The moment worth doing now, not a directory to comb through. Things that are true at a time and a place, and stop being
                            true.
                        </Promise>
                        <Promise title="It speaks only when it’s worth it.">
                            A few times a day at most, never at 3am, never while you’re driving. If there’s nothing worth saying, it stays quiet —
                            that’s the whole promise.
                        </Promise>
                        <Promise title="It learns what you’d have missed.">
                            A minute to calibrate, and then it gets quieter and better — surfacing the non-obvious and skipping what every guidebook
                            already told you.
                        </Promise>
                    </div>
                </main>

                <footer className="mx-auto w-full max-w-5xl px-6 py-8">
                    <div className="border-border-soft text-muted flex flex-wrap items-center justify-between gap-2 border-t pt-6 text-sm">
                        <span className="font-serif lowercase italic">{name}</span>
                        <span>
                            <Link href="/privacy-policy" className="hover:text-ink transition-colors">
                                Privacy
                            </Link>
                            <span className="px-2">·</span>
                            <Link href="/licenses" className="hover:text-ink transition-colors">
                                Data &amp; licences
                            </Link>
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}
