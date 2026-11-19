<?php

namespace Database\Seeders;

use App\Models\GlobalSetting;
use Illuminate\Database\Seeder;

class GlobalSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'ppn_rate' => [
                'rate' => 11,
                'enabled' => true,
            ],
            'service_fee' => [
                'amount' => 0,
                'type' => 'flat',
            ],
        ];

        foreach ($settings as $key => $value) {
            GlobalSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }
    }
}
