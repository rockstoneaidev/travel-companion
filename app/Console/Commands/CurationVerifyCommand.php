<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curation\Actions\AutoReviewCuratedItem;
use App\Domain\Curation\Actions\VerifyCuratedItem;
use App\Domain\Curation\Enums\CurationStatus;
use App\Domain\Curation\Models\CuratedItem;
use Illuminate\Console\Command;

/**
 * The machine reviewer, and the experiment that has to precede trusting it.
 *
 * --audit is the important mode, and it should be run FIRST.
 *
 * There are 149 approved items and zero rejections. Two stories explain that equally
 * well: the drafts are genuinely good because they are evidence-constrained, or the
 * review became a rubber stamp somewhere around item forty. From the outcome alone
 * they are indistinguishable — which is precisely the argument for a check that does
 * not get tired.
 *
 * So: run the verifier over what a human already approved, change nothing, and count
 * the disagreements.
 *
 *   · It clears them all  → the machine agrees with the human, and has earned the job.
 *   · It flags some       → either the verifier is too strict, or there are unsupported
 *                           claims ALREADY LIVE. Both are worth finding out here rather
 *                           than from a traveller in Nice.
 */
final class CurationVerifyCommand extends Command
{
    protected $signature = 'curation:verify
        {region? : limit to one region slug}
        {--audit : verify already-APPROVED items and report agreement, changing nothing}
        {--recheck : verify already-APPROVED items and DEMOTE the unsupported ones back to review}
        {--limit=50 : how many items to verify}';

    protected $description = 'Verify curated claims against their evidence; auto-approve what is fully supported.';

    public function handle(VerifyCuratedItem $verify, AutoReviewCuratedItem $autoReview): int
    {
        $audit = (bool) $this->option('audit');
        $recheck = (bool) $this->option('recheck');
        $limit = (int) $this->option('limit');

        $items = CuratedItem::query()
            ->when($this->argument('region'), fn ($q, $region) => $q->where('region_slug', $region))
            ->when(
                $audit || $recheck,
                fn ($q) => $q->where('status', CurationStatus::Approved),
                fn ($q) => $q->whereIn('status', VerifyCuratedItem::verifiable()),
            )
            ->limit($limit)
            ->get();

        if ($items->isEmpty()) {
            $this->info($audit
                ? 'Nothing approved to audit.'
                : 'Nothing awaiting verification. Draft a pack first.');

            return self::SUCCESS;
        }

        $this->info(($audit ? 'Auditing ' : 'Verifying ').$items->count().' item(s) with '.VerifyCuratedItem::PROMPT);

        $passed = 0;
        $flagged = [];

        foreach ($items as $item) {
            /*
             * AUDIT NEVER MUTATES STATUS. The measurement must not change the thing it
             * measures — an audit that quietly re-approved everything would prove only
             * that it had run.
             *
             * --recheck is the audit with teeth, and it exists because the audit found
             * things: claims copied verbatim from Wikivoyage and cut mid-word ("heated
             * by a sel."), and a live claim quoting a ticket price. Those are being
             * SERVED. An unsupported claim that a human waved through is not safer than
             * one a machine waved through — it is the same sentence in a traveller's ear.
             * So this demotes them back to review, where they should have stayed.
             */
            $verdict = $audit
                ? $verify($item)
                : $autoReview($item)['verdict'];

            if (($verdict['supported'] ?? false) === true) {
                $passed++;
                $this->line("  <fg=green>✓</> {$item->title}");

                continue;
            }

            $flagged[] = $item;
            $this->line("  <fg=yellow>?</> {$item->title} — ".($verdict['reason'] ?? 'unsupported'));
        }

        $total = $items->count();
        $this->newLine();

        if ($recheck) {
            $this->info("{$passed} still supported · ".count($flagged).' demoted to review (nothing was rejected — "unsupported" is a question, not an answer).');

            return self::SUCCESS;
        }

        if ($audit) {
            $agreement = $total === 0 ? 0.0 : round($passed / $total * 100, 1);

            $this->info("The machine agrees with the human on {$passed}/{$total} ({$agreement}%).");
            $this->line(count($flagged) === 0
                ? 'It clears everything you approved. It has earned the job.'
                : 'The disagreements above are either a verifier that is too strict, or claims that are already live and unsupported. Read a few before deciding which.');

            return self::SUCCESS;
        }

        $this->info("{$passed} auto-approved · ".count($flagged).' sent to a human.');

        return self::SUCCESS;
    }
}
