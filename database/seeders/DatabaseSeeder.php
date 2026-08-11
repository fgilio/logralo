<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The real group is seeded one member at a time with
        // `php artisan logralo:seed-member`, which also prints their link.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
