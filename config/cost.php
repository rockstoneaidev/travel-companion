<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Spend caps and the kill-switch (docs/COST.md §8)
|--------------------------------------------------------------------------
|
| Attribution tells you WHO cost you €4,000 from a looping client, after the fact.
| A cap means it never happens. With real users in the field and one operator, the
| kill-switch is worth more than the dashboard — so it is deterministic, it lives in
| config, and no model gets a vote (the spirit of non-negotiable #4).
|
| What a tripped cap does is DEGRADE, never stop: the voice falls back to the
| template (which is always true, just duller) and routing falls back to the
| estimator (whose number is the one we persist anyway). The product gets quieter.
| It does not go down. That is only possible because both fallbacks already exist
| and are already correct — we are not building a panic path, we are choosing an
| existing one.
|
*/

return [

    /*
    | The day boundary, for both the caps and the /admin cost strip.
    |
    | These MUST be the same clock: a strip that says "$3 spent today" while the
    | breaker thinks the day rolled over an hour ago is a strip nobody will trust
    | again. One timezone, named here, read by both.
    */
    'timezone' => env('COST_TIMEZONE', 'Europe/Stockholm'),

    'caps' => [
        /*
        | Whole-fleet daily ceiling, USD. Everything counts against it: user spend,
        | pack drafting, emulated admin sessions. The wallet does not care who spent
        | it (COST.md §7.2).
        */
        'daily_usd' => (float) env('COST_DAILY_CAP_USD', 10.0),

        /*
        | Per-user daily ceiling, USD. Catches one looping client without taking the
        | fleet down with it — which is the failure the global cap alone would cause.
        */
        'per_user_daily_usd' => (float) env('COST_USER_DAILY_CAP_USD', 1.0),
    ],

    /*
    | Alert thresholds, as a fraction of the daily cap. Fired once per threshold per
    | day. The dashboard is for mornings; these are for everything else.
    */
    'alerts' => [
        'enabled' => (bool) env('COST_ALERTS_ENABLED', true),
        'thresholds' => [0.5, 0.8, 1.0],
        'to' => env('COST_ALERT_EMAIL', env('PRIVACY_CONTACT_EMAIL', 'rockstoneaidev@gmail.com')),
    ],

    /*
    | Retention (COST.md §10, and the ROPA row that comes with it).
    |
    | A per-user cost log is a timestamped record of when someone used the app and how
    | hard — behavioural personal data, and arguably more revealing than the
    | recommendations themselves. So the identifying columns are nulled on a schedule,
    | while the MONEY stays: erasure must detach the person without blowing a hole in
    | the P&L. That is also why `cost_events` has no foreign key to `users` — a
    | cascade would delete the accounting.
    */
    'deidentify_after_days' => (int) env('COST_DEIDENTIFY_AFTER_DAYS', 90),
    'delete_after_days' => (int) env('COST_DELETE_AFTER_DAYS', 730),

];
