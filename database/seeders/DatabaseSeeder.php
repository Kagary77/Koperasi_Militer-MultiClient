<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GlobalSettingSeeder::class,
            UnitSeeder::class,
            MasterCategorySeeder::class,
            SuperAdminSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoKoperasiSeeder::class);
        }
    }
}
