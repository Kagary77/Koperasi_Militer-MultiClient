<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\PaymentChargeRequest;
use App\Modules\Payments\Data\QrisCharge;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use Illuminate\Support\Str;

final class FakeQrisGateway implements PaymentGateway
{
    public function __construct(private bool $unavailable = false) {}

    public function createQrisCharge(PaymentChargeRequest $request): QrisCharge
    {
        if ($this->unavailable) {
            throw new GatewayUnavailableException('Gateway QRIS sandbox tidak merespons.');
        }

        $providerReference = 'SBX-QRIS-'.strtoupper(Str::random(18));
        $payload = $this->buildEmvcoPayload(
            amount: $request->amount,
            reference: $request->paymentReference,
            merchantName: $request->koperasiName,
        );

        return new QrisCharge(
            providerReference: $providerReference,
            qrisPayload: $payload,
            expiresAt: now()->addSeconds($request->expiresInSeconds),
        );
    }

    public function name(): string
    {
        return 'fake-qris-sandbox';
    }

    public function isSandbox(): bool
    {
        return true;
    }

    private function buildEmvcoPayload(int $amount, string $reference, string $merchantName): string
    {
        $cleanMerchant = strtoupper(substr(preg_replace('/[^A-Za-z0-9 ]/', '', $merchantName) ?: 'KOPERASI MILITER', 0, 25));

        $fields = $this->tlv('00', '01')              // Payload Format Indicator
            .$this->tlv('01', '12')                   // Dynamic QRIS
            .$this->tlv('52', '5411')                 // Merchant Category Code (Grocery/Sembako)
            .$this->tlv('53', '360')                  // Currency: IDR
            .$this->tlv('54', (string) $amount)       // Nominal presisi (Rupiah bulat)
            .$this->tlv('58', 'ID')                   // Country Code
            .$this->tlv('59', $cleanMerchant)         // Nama Koperasi
            .$this->tlv('60', 'INDONESIA')            // City
            .$this->tlv('62', $this->tlv('05', substr($reference, 0, 25)));

        $withCrcTag = $fields.'6304';

        return $withCrcTag.$this->crc16($withCrcTag);
    }

    private function tlv(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    private function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
