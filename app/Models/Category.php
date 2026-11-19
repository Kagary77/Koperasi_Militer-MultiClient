<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function isGlobal(): bool
    {
        return $this->koperasi_id === null;
    }

    public function scopeAvailableForKoperasi(Builder $query, int $koperasiId): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $q) use ($koperasiId): void {
                $q->whereNull('koperasi_id')
                    ->orWhere('koperasi_id', $koperasiId);
            });
    }

    public function koperasi(): BelongsTo
    {
        return $this->belongsTo(Koperasi::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
