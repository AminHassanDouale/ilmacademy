<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    public function run()
    {
        if (Brand::count()) {
            return;
        }

        Brand::insert([
            [
                'id' => 1,
                'name' => 'Apple',
            ],
            [
                'id' => 2,
                'name' => 'Samsung',
            ],
            [
                'id' => 3,
                'name' => 'LG',
            ]
        ]);
    }
}
