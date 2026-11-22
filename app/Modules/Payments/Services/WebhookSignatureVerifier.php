<?php

namespace App\Modules\Payments\Services;

use App\Models\Koperasi;

final class WebhookSignatureVerifier
{
    public const HEADER_NAME = 'X-Qris-Signature';

    public function sign(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    public function verify(string $rawBody, ?string $signature, Koperasi $koperasi): bool
    {
        if ($signature === null || trim($signature) === '') {
            return false;
        }

        $credentials = $koperasi->gateway_credentials;
        $secret = is_array($credentials) ? (string) ($credentials['webhook_secret'] ?? '') : '';

        if ($secret === '') {
            return false;
        }

        $expected = $this->sign($rawBody, $secret);

        return hash_equals($expected, trim($signature));
    }
}
