<?php

namespace App\Services\Shared;

use App\Models\AuditLog;
use App\Support\Tenancy\KoperasiContext;
use Illuminate\Support\Facades\Auth;

final class AuditLogger
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'mfa_secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'gateway_merchant_id',
        'gateway_credentials',
        'bank_account_number',
    ];

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $action,
        string $modelType,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $koperasiId = null,
        string $actorType = 'user',
        ?int $userId = null,
    ): AuditLog {
        $resolvedUserId = $actorType === 'user' ? ($userId ?? Auth::guard('web')->id()) : null;
        $resolvedKoperasiId = $koperasiId ?? app(KoperasiContext::class)->idOrNull();

        $log = new AuditLog;
        $log->forceFill([
            'user_id' => $resolvedUserId,
            'actor_type' => $actorType,
            'koperasi_id' => $resolvedKoperasiId,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => $oldValues !== null ? $this->sanitize($oldValues) : null,
            'new_values' => $newValues !== null ? $this->sanitize($newValues) : null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ])->save();

        return $log;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
