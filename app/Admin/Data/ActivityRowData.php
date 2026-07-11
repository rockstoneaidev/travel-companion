<?php

declare(strict_types=1);

namespace App\Admin\Data;

use Spatie\Activitylog\Models\Activity;

final readonly class ActivityRowData
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public int $id,
        public string $description,
        public ?string $causer,
        public ?string $subject,
        public array $properties,
        public string $createdAt,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        return new self(
            id: $activity->id,
            description: $activity->description,
            causer: $activity->causer?->email ?? null,
            subject: $activity->subject_type !== null
                ? class_basename($activity->subject_type).'#'.$activity->subject_id
                : null,
            properties: $activity->properties->all(),
            createdAt: $activity->created_at->toISOString(),
        );
    }
}
