@if ($percent >= 100)
The daily spend cap has been REACHED.

Spend today: ${{ number_format($spentUsd, 2) }} of the ${{ number_format($capUsd, 2) }} cap.

Paid calls are now blocked. The product keeps serving: opportunity summaries fall back
to the template (dull, and always true) and travel times fall back to the estimator.
Nothing is down, and nothing more will be spent today.

If this was not expected, the cost strip on /admin shows the biggest line item, and
the per-user ceiling stops one runaway client without stopping everyone else.
@else
Spend today has passed {{ $percent }}% of the daily cap.

Spend today: ${{ number_format($spentUsd, 2) }} of the ${{ number_format($capUsd, 2) }} cap.

No action has been taken. This is a warning, not a block — at 100% paid calls degrade
to the template and the estimator automatically.
@endif

—
Travel Companion · cost guard (docs/COST.md §8)
