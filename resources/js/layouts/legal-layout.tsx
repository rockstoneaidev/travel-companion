import { AppHeader } from '@/components/app';
import ProductLayout from '@/layouts/product-layout';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren, type ReactNode } from 'react';

interface LegalLayoutProps {
    /** Browser tab + <h1>. */
    title: string;
    /** The one-sentence serif lede under the headline. */
    lede: ReactNode;
    /** ISO date the document was last substantively changed. */
    updated: string;
}

/**
 * The chrome for /privacy-policy and /terms-of-service.
 *
 * These are the two screens where the temptation is to switch into a different
 * voice — the small-print voice, the one that exists to be scrolled past. DESIGN §1
 * says the app speaks plainly, and a legal page is exactly where that either means
 * something or doesn't. So: the same paper, the same serif, the same measure. The
 * only concession is `max-w-prose` instead of `max-w-md`, because these are long and
 * a 28rem column would make them punishing.
 */
export default function LegalLayout({ title, lede, updated, children }: PropsWithChildren<LegalLayoutProps>) {
    const { name, auth } = usePage<SharedData>().props;

    // These two pages are PUBLIC on purpose — Art. 13 wants the notice readable at the
    // moment data is obtained, which is the sign-up form, so a signed-out visitor has to
    // be able to load them. That rules out wrapping them in the app shell unconditionally:
    // the sidebar navigates to Dashboard/Explore/Kept and renders the user footer, and a
    // guest has no user. So the shell follows the reader, not the route — signed in, you
    // get the same navigation as every other screen; signed out, you get the document.
    const page = (
        <>
            <Head title={title} />
            <div className="mx-auto max-w-prose space-y-8 px-5 py-8">
                <AppHeader />

                <div className="space-y-4">
                    <h1 className="text-headline text-ink font-serif font-medium italic">{title}</h1>
                    <p className="text-lede text-body font-serif italic">{lede}</p>
                    <p className="text-facet text-meta font-medium tracking-[.12em] uppercase">
                        Last updated{' '}
                        {new Date(updated).toLocaleDateString('en-GB', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        })}
                    </p>
                </div>

                <div className="space-y-8">{children}</div>

                <footer className="border-border-soft flex flex-wrap gap-x-4 gap-y-1 border-t pt-6">
                    <Link href="/privacy-policy" className="text-body-card text-body underline underline-offset-[3px]">
                        Privacy
                    </Link>
                    <Link href="/terms-of-service" className="text-body-card text-body underline underline-offset-[3px]">
                        Terms
                    </Link>
                    <Link href="/licenses" className="text-body-card text-body underline underline-offset-[3px]">
                        Data &amp; licenses
                    </Link>
                    <span className="text-body-card text-meta ml-auto">{name}</span>
                </footer>
            </div>
        </>
    );

    if (auth.user) {
        return <ProductLayout>{page}</ProductLayout>;
    }

    return <div className="bg-paper min-h-screen">{page}</div>;
}

/** A titled block. Kept here so both documents are laid out by one thing, not two. */
export function LegalSection({ heading, children }: PropsWithChildren<{ heading: string }>) {
    return (
        <section className="space-y-3">
            <h2 className="text-body-detail text-ink font-serif font-medium">{heading}</h2>
            <div className="text-body-card text-body space-y-3">{children}</div>
        </section>
    );
}
