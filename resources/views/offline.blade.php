{{--
    The offline fallback (SCREENS.md S11).

    Deliberately self-contained: no Vite bundle, no webfont, no image. This page is
    precached by the service worker and must render with literally zero network, so it
    cannot depend on a hashed asset that may have been evicted. System serif stands in
    for Newsreader — the one place the type is allowed to degrade.

    The voice is the product's own (DESIGN §6): honest, specific, unapologetic. It is a
    designed state, not an error page — so no retry-hammering, no sad illustration.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>Offline — {{ config('app.name') }}</title>
        <meta name="theme-color" content="#F6F0E4" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#221B13" media="(prefers-color-scheme: dark)">
        <style>
            :root {
                --paper: #F6F0E4;
                --ink: #3B2F24;
                --body: #5C5142;
                --meta: #6E6149;
                --border-strong: #CBBB9C;
                --border-soft: #EFE4CC;
                --urgent: #C9963F;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --paper: #221B13;
                    --ink: #EFE6D6;
                    --body: #C4B69E;
                    --meta: #A08F6F;
                    --border-strong: #574733;
                    --border-soft: #3E3323;
                    --urgent: #D9AC5C;
                }
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100svh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem 1.25rem;
                background: var(--paper);
                color: var(--ink);
                font-family: ui-sans-serif, system-ui, sans-serif;
                -webkit-text-size-adjust: 100%;
            }

            main { max-width: 22rem; text-align: center; }

            .mark {
                width: 3.5rem;
                height: 3.5rem;
                margin: 0 auto 1.5rem;
                border: 1px dashed var(--border-strong);
                border-radius: 999px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .mark span {
                width: 0.5rem;
                height: 0.5rem;
                border-radius: 999px;
                background: var(--urgent);
            }

            h1 {
                margin: 0 0 1rem;
                font-family: ui-serif, Georgia, 'Times New Roman', serif;
                font-style: italic;
                font-weight: 500;
                font-size: 1.5rem;
                line-height: 1.25;
            }

            p {
                margin: 0;
                color: var(--body);
                font-size: 0.8125rem;
                line-height: 1.5;
            }

            .footer {
                margin-top: 2rem;
                padding-top: 1rem;
                border-top: 1px solid var(--border-soft);
                color: var(--meta);
                font-size: 0.625rem;
                letter-spacing: 0.12em;
                text-transform: uppercase;
            }
        </style>
    </head>
    <body>
        <main>
            <div class="mark"><span></span></div>
            <h1>I can&rsquo;t look around right now.</h1>
            <p>
                There&rsquo;s no connection here. What you kept is still yours &mdash; it&rsquo;s on this
                device, and it doesn&rsquo;t need me. I&rsquo;ll pick the thread back up when you&rsquo;re
                back on the network.
            </p>
            <p class="footer">Offline</p>
        </main>
    </body>
</html>
