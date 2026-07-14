<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Devices;

use App\Domain\Trips\Data\NewDeviceData;
use App\Domain\Trips\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A device belongs to whoever is holding it. There is no ability to check beyond
        // "are you signed in" — the token is bound to the caller, not chosen by them.
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'push_token' => ['required', 'string', 'min:8', 'max:512'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function toData(): NewDeviceData
    {
        return new NewDeviceData(
            userId: (int) $this->user()->id,
            platform: DevicePlatform::from((string) $this->input('platform')),
            pushToken: (string) $this->input('push_token'),
            appVersion: $this->input('app_version') === null ? null : (string) $this->input('app_version'),
        );
    }
}
