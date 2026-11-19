<?php

namespace App\Support\Tenancy;

use App\Models\Koperasi;
use RuntimeException;

final class KoperasiContext
{
    private ?Koperasi $koperasi = null;

    public function set(Koperasi $koperasi): void
    {
        $this->koperasi = $koperasi;
    }

    public function clear(): void
    {
        $this->koperasi = null;
    }

    public function has(): bool
    {
        return $this->koperasi !== null;
    }

    public function koperasi(): Koperasi
    {
        return $this->koperasi ?? throw new RuntimeException('KoperasiContext belum diisi.');
    }

    public function id(): int
    {
        return (int) $this->koperasi()->id;
    }

    public function idOrNull(): ?int
    {
        return $this->koperasi !== null ? (int) $this->koperasi->id : null;
    }
}
