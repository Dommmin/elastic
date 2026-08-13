<?php

namespace Database\Factories;

use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Seller>
 */
class SellerFactory extends Factory
{
    protected $model = Seller::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'version' => 1,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'rating' => fake()->numberBetween(1, 5),
            'city' => fake()->city(),
            'lat' => fake()->latitude(49, 55),
            'lon' => fake()->longitude(14, 24),
        ];
    }
}
