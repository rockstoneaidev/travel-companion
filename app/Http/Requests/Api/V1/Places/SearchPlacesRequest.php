<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Places;

use Illuminate\Foundation\Http\FormRequest;

final class SearchPlacesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['sometimes', 'integer', 'between:1,20'],
        ];
    }

    public function searchTerm(): string
    {
        return (string) $this->string('q');
    }

    public function limit(): int
    {
        return (int) ($this->integer('limit') ?: 8);
    }
}
