<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\KoperasiContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToKoperasi
{
    public static function bootBelongsToKoperasi(): void
    {
        static::addGlobalScope('koperasi', function (Builder $builder): void {
            $context = app(KoperasiContext::class);

            if ($context->has()) {
                $builder->where($builder->qualifyColumn('koperasi_id'), $context->id());
            }
        });

        static::creating(function (Model $model): void {
            $context = app(KoperasiContext::class);

            if ($context->has() && empty($model->getAttribute('koperasi_id'))) {
                $model->setAttribute('koperasi_id', $context->id());
            }
        });
    }
}
