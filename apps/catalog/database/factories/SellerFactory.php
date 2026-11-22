<?php

namespace Database\Factories;

use App\Models\Seller;
use App\Models\User;
use App\Support\TaxIdGenerator;
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
            // Zakres ziarn z dala od migracji (ziarno = id) i RaceFixture; unique() pilnuje kolizji.
            'nip' => TaxIdGenerator::forSeed(60_000_000 + fake()->unique()->numberBetween(1, 5_000_000)),
            'synthetic' => false,
            'lat' => fake()->latitude(49, 55),
            'lon' => fake()->longitude(14, 24),
            'status' => 'active',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function blocked(): static
    {
        return $this->state(['status' => 'blocked']);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
