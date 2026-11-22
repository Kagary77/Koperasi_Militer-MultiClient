<?php

namespace App\Modules\Payments\Data;

use Carbon\CarbonInterface;

final readonly class QrisCharge
{
    public function __construct(
        public string $providerReference,
        public string $qrisPayload,
        public CarbonInterface $expiresAt,
    ) {}
}
