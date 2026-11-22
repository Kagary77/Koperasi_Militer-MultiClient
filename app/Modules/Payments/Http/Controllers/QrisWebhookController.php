<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\PaymentWebhookService;
use App\Modules\Payments\Services\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QrisWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentWebhookService $service): JsonResponse
    {
        $rawBody = $request->getContent();
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            return response()->json([
                'code' => 'invalid_json',
                'message' => 'Body webhook harus berupa JSON yang valid.',
            ], 422);
        }

        $signature = $request->header(WebhookSignatureVerifier::HEADER_NAME);
        $result = $service->handleWebhook($rawBody, $signature, $decoded);

        return response()->json([
            'code' => $result['code'],
            'message' => $result['message'],
        ], $result['http_status']);
    }
}
