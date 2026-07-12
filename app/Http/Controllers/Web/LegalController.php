<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The two public legal pages (docs/legal/).
 *
 * PUBLIC AND UNAUTHENTICATED, deliberately. A privacy notice you can only read after
 * signing up is a privacy notice that arrives after the decision it exists to inform
 * (Art. 13 requires it "at the time when personal data are obtained"). Both pages sit
 * outside the `auth` group for that reason, not by oversight.
 *
 * THE RETENTION NUMBER COMES FROM CONFIG, never from the page. `config/privacy.php` is
 * what the nightly retention job actually enforces, so it is the only honest source for
 * a sentence that says "I destroy this after N days". Type the number into the JSX and
 * the day someone tunes the policy, the notice quietly becomes a false statement to a
 * data subject — which is the exact failure mode the DPIA calls "laundering the risk".
 */
final class LegalController extends Controller
{
    /**
     * The dates the documents were last SUBSTANTIVELY changed.
     *
     * Hard-coded on purpose: a "last updated" stamp driven by the file mtime or the
     * deploy would move every time a typo was fixed, which trains people to ignore it.
     * It should move when the *meaning* moves, and that is a human judgement.
     */
    private const PRIVACY_UPDATED = '2026-07-12';

    private const TERMS_UPDATED = '2026-07-12';

    public function privacy(): Response
    {
        return Inertia::render('privacy-policy', [
            'retentionDays' => (int) config('privacy.raw_location_retention_days'),
            'contactEmail' => config('privacy.controller_email'),
            'updated' => self::PRIVACY_UPDATED,
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('terms-of-service', [
            'contactEmail' => config('privacy.controller_email'),
            'updated' => self::TERMS_UPDATED,
        ]);
    }
}
