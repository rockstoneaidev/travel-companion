<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Data\PushMessage;

/**
 * The delivery rail, behind a port (conventions/09, PRD Appendix A).
 *
 * FCM today, APNs direct if it ever has to be, a log line in development. A port, because
 * the day this product needs to leave Google's push infrastructure should be a swap and
 * not a rewrite — the same reason `LlmClient` and `Routing` are ports.
 *
 * The implementation never decides ANYTHING. It is handed a message that a deterministic
 * policy already approved, and its only job is to get it to a handset or say honestly that
 * it could not.
 */
interface PushSender
{
    /** @return string|null a vendor message id, or null when the send failed */
    public function send(PushMessage $message): ?string;
}
