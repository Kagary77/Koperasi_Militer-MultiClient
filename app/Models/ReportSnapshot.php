<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_sales' => 'integer',
            'total_transactions' => 'integer',
            'total_online_orders' => 'integer',
            'total_pos_orders' => 'integer',
            'total_active_customers' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function koperasi(): BelongsTo
    {
        return $this->belongsTo(Koperasi::class);
    }
}
