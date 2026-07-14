<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Notifications\Enums\NotificationGate;

/** Whether to interrupt, under which policy version, and — when we didn't — why not. */
final readonly class NotificationDecision
{
    /** @param array<string, mixed> $trace */
    private function __construct(
        public bool $allowed,
        public string $policyVersion,
        public ?NotificationGate $deniedBy = null,
        public ?float $priority = null,
        public array $trace = [],
    ) {}

    /** @param array<string, mixed> $trace */
    public static function allowed(string $version, float $priority, array $trace): self
    {
        return new self(allowed: true, policyVersion: $version, priority: $priority, trace: $trace);
    }

    /** @param array<string, mixed> $trace */
    public static function denied(NotificationGate $gate, string $version, array $trace): self
    {
        return new self(allowed: false, policyVersion: $version, deniedBy: $gate, trace: $trace);
    }
}
