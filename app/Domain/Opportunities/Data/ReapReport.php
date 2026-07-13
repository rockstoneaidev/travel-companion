<?php

declare(strict_types=1);

namespace App\Domain\Opportunities\Data;

/** What one nightly reap pass did — logged every night, including the quiet ones. */
final readonly class ReapReport
{
    public function __construct(
        public int $archived,
        public int $archivedEvidence,
        public int $reaped,
    ) {}

    /** @return array{archived: int, archived_evidence: int, reaped: int} */
    public function toArray(): array
    {
        return [
            'archived' => $this->archived,
            'archived_evidence' => $this->archivedEvidence,
            'reaped' => $this->reaped,
        ];
    }
}
