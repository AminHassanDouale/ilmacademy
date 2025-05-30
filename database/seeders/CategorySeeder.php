<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run()
    {
        if (Category::count()) {
            return;
        }

        Category::insert([
            [
                'id' => 1,
                'name' => 'Computer'
            ],
            [
                'id' => 2,
                'name' => 'Smartphone'
            ],
            [
                'id' => 3,
                'name' => 'Sound'
            ]
        ]);
    }
}
