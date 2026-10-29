<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'total_amount' => fake()->randomFloat(2, 10, 1000),
            'status' => OrderStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Completed,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
        ]);
    }
}
