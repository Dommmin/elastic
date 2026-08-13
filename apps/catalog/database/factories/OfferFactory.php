<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'version' => 1,
            'product_id' => Product::factory(),
            'seller_id' => Seller::factory(),
            'price_cents' => fake()->numberBetween(1000, 500000),
            'currency' => 'PLN',
            'stock' => fake()->numberBetween(0, 100),
            'condition' => fake()->randomElement(['new', 'used']),
            'shipping_days' => fake()->numberBetween(1, 14),
            'active' => true,
        ];
    }
}
