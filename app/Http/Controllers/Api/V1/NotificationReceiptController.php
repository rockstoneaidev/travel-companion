<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\Actions\RecordNotificationReceipt;
use App\Domain\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "I opened it" / "I swiped it away" (E31; PRD §12).
 *
 * The receipt is the moat closing: it is the only signal that measures whether an
 * INTERRUPTION was worth it, as distinct from whether the place was any good.
 */
final class NotificationReceiptController extends Controller
{
    public function opened(Request $request, Notification $notification, RecordNotificationReceipt $receipt): JsonResponse
    {
        $this->own($request, $notification);

        $receipt->opened($notification);

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }

    public function dismissed(Request $request, Notification $notification, RecordNotificationReceipt $receipt): JsonResponse
    {
        $this->own($request, $notification);

        $receipt->dismissed($notification);

        return new JsonResponse(status: JsonResponse::HTTP_NO_CONTENT);
    }

    private function own(Request $request, Notification $notification): void
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);
    }
}
