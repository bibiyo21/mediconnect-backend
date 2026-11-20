<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data only (roles, permissions, specialties) — added from increment 5.
 * Never seed real patients, doctors or HMO records.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RbacSeeder::class);
        $this->call(SpecialtySeeder::class);
        $this->call(SearchSynonymSeeder::class);
    }
}
