<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct(
        private SmsService $smsService
    ) {}

    /**
     * Get messages ready to be sent.
     *
     * This endpoint retrieves pending SMS messages that:
     * - Have status = 0 (not yet sent)
     * - Provider is 'inhousesms'
     * - send_after is before current time (if set)
     * - Local time is between 9 AM and 11 PM
     *
     * After retrieval, messages are marked as sent (status = 1, sent = 1).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMessagesToSend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'integer|min:1|max:100',
            'provider' => 'string|in:' . implode(',', \App\Models\LogsSms::VALID_PROVIDERS),
        ]);

        $limit = $validated['limit'] ?? 5;
        $provider = $validated['provider'] ?? 'inhousesms';

        try {
            $messages = $this->smsService->getAndMarkMessagesToSend($limit, $provider);

            return response()->json([
                'success' => true,
                'count' => $messages->count(),
                'data' => $messages->map(fn ($msg) => [
                    'id' => $msg->id,
                    'phone' => $msg->phone,
                    'message' => $msg->message,
                    'provider' => $msg->provider,
                    'time_zone' => $msg->time_zone,
                    'send_after' => $msg->send_after?->toIso8601String(),
                    'sent_at' => $msg->sent_at?->toIso8601String(),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to retrieve messages',
                'message' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get statistics about SMS messages.
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        $stats = $this->smsService->getStatistics();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

