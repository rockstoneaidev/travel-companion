<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trips\Actions\RegisterDevice;
use App\Domain\Trips\Actions\RevokeDevice;
use App\Domain\Trips\Models\Device;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Devices\StoreDeviceRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The push-token registry (E29). A device is the address of somebody's pocket. */
final class DeviceController extends Controller
{
    public function store(StoreDeviceRequest $request, RegisterDevice $register): JsonResponse
    {
        return (new DeviceResource($register($request->toData())))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function destroy(Request $request, Device $device, RevokeDevice $revoke): JsonResponse
    {
        // Ownership, and nothing else: you may silence your own phone and no one else's.
        abort_unless((int) $device->user_id === (int) $request->user()->id, 403);

        $revoke($device);

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }
}
