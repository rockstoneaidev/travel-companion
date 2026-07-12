# Gold traces (PRD §15.2)

Recorded 2026-07-14, before the France trip. Two real Stockholm sessions —
Liljeholmen and Norrmalm — served by the pipeline as it stands.

## What they are for

`php artisan replay:gold` re-runs each trace and tells you whether the serve
changed. That is the question you actually want answered before shipping a
scoring change during a trip: **did what I just did alter what people are shown?**

## The caveat, and it is a real one

**A trace replays against the LIVE database.** It is therefore sensitive to three
different things, and the command's verdict says so out loud — *"diverged (data,
constants, or profile changed since)"*:

1. **the pipeline** — what we are actually trying to detect;
2. **the world model** — ingest more places and the candidate pool changes;
3. **the user's taste profile** — α moves, and with it the cold-start rules.

So a red gold suite is a **question**, not a verdict. Read the diff before
believing it, and re-record deliberately rather than to make the red go away.
Re-recording a drift you have not understood is how a regression gets laundered
into a fixture.

Both of the traces here diverged the first time they were checked, and both
divergences were legitimate:

- composites rose ~0.03–0.06 — E16 gave daylight and weather a say in
  `temporal_urgency`, which is exactly what it was supposed to do;
- the feed shrank from 5 items to 4 — the test user's profile had been wiped, so
  α fell to 0, and a cold user is served for facet COVERAGE (PRD §11 rule c),
  which stops early rather than repeating a facet.

Neither was a bug. The one bug the replayer *did* find on that pass was real:
verify-before-recommend was backfilling the feed with candidates that had never
been through `FeedSelector`, so they carried a null `composite` and bypassed the
diversity and α logic entirely. In the console that throws; in production
"Undefined array key" is only a warning, so it had been happening silently.

That is the whole argument for this directory.

## Re-recording

    php artisan replay:record {session-id}     # writes {session-id}.json here
    php artisan replay:gold                    # checks every trace
