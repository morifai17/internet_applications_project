<?php

namespace Database\Factories;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @var class-string<Product>
     */
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'title' => Str::title(fake()->unique()->sentence(3)),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 5, 1500),
            'stock_quantity' => fake()->numberBetween(0, 100),
            'image_path' => null,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => [
            'stock_quantity' => 0,
        ]);
    }
}
