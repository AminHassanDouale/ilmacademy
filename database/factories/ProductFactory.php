<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition()
    {
        return [
            'category_id' => Category::inRandomOrder()->first(),
            'brand_id' => Brand::inRandomOrder()->first(),
            'name' => $this->faker->name(),
            'description' => $this->faker->text(400),
            'price' => $this->faker->randomFloat(2, 100, 9000),
            'cover' => 'https://picsum.photos/400?x=' . rand(),
        ];
    }
}
