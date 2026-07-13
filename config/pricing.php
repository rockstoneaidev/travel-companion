<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vendor prices, as dated sheets (docs/COST.md §6)
|--------------------------------------------------------------------------
|
| Money is integer USD MICROS everywhere — never a float. Both Google Cloud and
| Gemini bill in USD; EUR conversion happens at report time with a dated rate, not
| here (COST.md §2.4).
|
| A price change is a NEW dated sheet, never an edit to an existing one. Every
| `cost_events` row stamps the sheet key it was priced with (`price_version`), so
| history can never silently re-price itself — which is the whole reason this is a
| reviewed config file rather than a table someone can UPDATE.
|
| Do NOT wire a third-party price feed into this file. The drift check (COST.md
| §6.1) compares the feeds against these numbers and shouts; a human lands the new
| sheet. A ledger repriced by a feed that moved underneath it is not an audit trail.
|
*/

return [

    /*
    | The sheet in force. Bump this when you add a new dated sheet below.
    */
    'version' => env('PRICE_VERSION', '2026-07'),

    'sheets' => [

        /*
        | 2026-07 — taken from the public price pages on 2026-07-13.
        |
        | ⚠️ UNVERIFIED against the billing console (COST.md §12.1). The token rates
        | below are list prices; the CACHED-input rates are the least certain of the
        | lot and are currently set equal to the input rate, which OVER-states the
        | cost of a cached read. Deliberately so: a ledger that overestimates spend
        | fails safe (the kill-switch trips early), one that underestimates does not.
        */
        '2026-07' => [

            /*
            | LLM — USD micros per 1,000,000 tokens.
            |
            | flash-lite is the serve path (opportunity voice); 3.5-flash is the
            | capable tier and only ever runs on the pack/curation pipeline, where it
            | is region capex rather than a user's cost (COST.md §2.1).
            */
            'llm' => [
                'gemini-3.1-flash-lite' => [
                    'input' => 250_000,        // $0.25 / 1M
                    'output' => 1_500_000,     // $1.50 / 1M
                    'cached_input' => 250_000, // see the warning above
                ],
                'gemini-3.5-flash' => [
                    'input' => 1_500_000,      // $1.50 / 1M
                    'output' => 9_000_000,     // $9.00 / 1M
                    'cached_input' => 1_500_000,
                ],
            ],

            /*
            | Outbound APIs — USD micros per call, keyed by the resource slug that
            | `hosts` below maps a hostname onto.
            |
            | `free` is not a placeholder: every open source we call (Overpass,
            | Wikidata, Open-Meteo, Overture, Datatourisme, Mérimée) is metered at
            | zero and still recorded. Volume is worth knowing even when it is free —
            | it is what tells you a scout has started looping.
            |
            | Google's free monthly allowance (10k Essentials events) is NOT modelled
            | here. These rows bill the list price from call one, and the free-tier
            | gauge (COST.md §7.4) reports the allowance separately. Two numbers that
            | mean different things: "what we would owe" and "what we will be
            | invoiced". Netting them here would hide the first.
            */
            'api' => [
                'routes_essentials' => 5_000,          // $5.00 / 1,000
                'place_details_essentials' => 5_000,   // $5.00 / 1,000
                'place_details_pro' => 17_000,         // $17.00 / 1,000
                'free' => 0,
            ],
        ],
    ],

    /*
    | Hostname → resource slug. The global HTTP hook (AppServiceProvider) knows only
    | a host, and this is where a host becomes a priced thing.
    |
    | `null` means "metered elsewhere, do not price this row": the Gemini host is
    | recorded by GeminiClient with its real token counts, and pricing it here as
    | well would double-count every generation.
    |
    | An UNKNOWN host is deliberately not an error and not free — it lands as
    | `unknown` with a zero price and is surfaced in the admin (COST.md §7.3). A new
    | paid API added by someone who forgot this file should look conspicuous, not
    | invisible.
    */
    'hosts' => [
        'generativelanguage.googleapis.com' => null,

        'routes.googleapis.com' => 'routes_essentials',
        'places.googleapis.com' => 'place_details_essentials',

        /*
        | Overpass is free — but it is a FLEET, and the adapter picks a mirror. The first
        | real ingest logged hundreds of "spend recorded with no price" warnings for
        | `lz4.overpass-api.de`, which is the instrumentation working exactly as designed
        | (an unlisted host is `unknown`, never silently `free` — a paid API nobody
        | configured must look conspicuous). It just happened to be right about a host
        | that is genuinely free. Every mirror the adapter can choose is listed here.
        */
        'overpass-api.de' => 'free',
        'lz4.overpass-api.de' => 'free',
        'overpass.kumi.systems' => 'free',

        'query.wikidata.org' => 'free',
        'www.wikidata.org' => 'free',
        'en.wikipedia.org' => 'free',
        'api.open-meteo.com' => 'free',
        'api.sunrise-sunset.org' => 'free',
        'data.datatourisme.fr' => 'free',
        'diffuseur.datatourisme.fr' => 'free',
        'www.pop.culture.gouv.fr' => 'free',
        'overturemaps.org' => 'free',
    ],

    /*
    | Vendor grouping for the admin breakdown. Same hostnames, coarser bucket.
    */
    'vendors' => [
        'generativelanguage.googleapis.com' => 'gemini',
        'routes.googleapis.com' => 'google_maps',
        'places.googleapis.com' => 'google_maps',
    ],

    /*
    | Google's free monthly allowance, per SKU tier — the "when do I actually start
    | paying" number (COST.md §7.4). Free-tier usage bills $0 while eating runway, so
    | it is invisible in every spend-based view. Counted, not netted.
    */
    'free_tier' => [
        'monthly_essentials_events' => 10_000,
    ],

];
