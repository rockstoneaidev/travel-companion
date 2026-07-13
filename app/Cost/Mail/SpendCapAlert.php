<?php

declare(strict_types=1);

namespace App\Cost\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "You are spending money faster than you meant to" (docs/COST.md §7.4, §8).
 *
 * A Mailable rather than a `Mail::raw()`, and the reason is worth writing down because
 * the first draft of this WAS raw: `MailFake::raw()` is a literal no-op — it records
 * nothing — so a raw alert cannot be asserted in a test at all. An alert nobody can
 * prove works is an alert you find out about on the day it does not, which for this
 * particular email is the day you wanted it most.
 *
 * Plain text, no markdown, no images: this is an operational alert read on a phone,
 * possibly abroad, possibly in a hurry.
 */
final class SpendCapAlert extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly int $percent,
        public readonly float $spentUsd,
        public readonly float $capUsd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->percent >= 100
                ? sprintf('Spend cap REACHED — paid calls are degrading ($%.2f)', $this->spentUsd)
                : sprintf("Spend at %d%% of today's cap ($%.2f of $%.2f)", $this->percent, $this->spentUsd, $this->capUsd),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.spend-cap-alert');
    }
}
