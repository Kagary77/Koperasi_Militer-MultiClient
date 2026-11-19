<?php

namespace App\Models;

use App\Models\Concerns\BelongsToKoperasi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToKoperasi;

    protected $fillable = [
        'status',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate_snapshot' => 'decimal:2',
            'total_tax' => 'integer',
            'total_fee' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    public function isPendingPayment(): bool
    {
        return $this->status === 'pending_payment';
    }

    public function koperasi(): BelongsTo
    {
        return $this->belongsTo(Koperasi::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class);
    }
}
