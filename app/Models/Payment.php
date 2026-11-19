<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expired_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function isSettled(): bool
    {
        return $this->status === 'settled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->expired_at !== null && $this->expired_at->isPast());
    }

    public function koperasi(): BelongsTo
    {
        return $this->belongsTo(Koperasi::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
