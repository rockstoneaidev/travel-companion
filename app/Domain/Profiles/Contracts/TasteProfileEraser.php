<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Contracts;

/**
 * Forget what we concluded about someone (conventions/01).
 *
 * Privacy needs this: withdrawing consent must delete the profile, because holding
 * a vector from which a person's religious belief can be deduced is itself
 * processing, and without consent there is no basis for it (Art. 9, DPIA §3.2).
 *
 * But Privacy may not reach into Profiles' actions to do it — the arch test caught
 * that, for the fourth time this week, which is a good argument for the arch test.
 */
interface TasteProfileEraser
{
    public function eraseForUser(int $userId): void;
}
