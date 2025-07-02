<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    protected $model = PaymentPlan::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(['monthly', 'quarterly', 'annual', 'one-time']);
        $baseAmount = $this->faker->numberBetween(50, 500);

        return [
            'name' => $this->faker->words(3, true) . ' Plan',
            'description' => $this->faker->sentence(),
            'type' => $type,
            'amount' => $baseAmount,
            'currency' => $this->faker->randomElement(['USD', 'EUR', 'GBP']),
            'installments' => PaymentPlan::getDefaultInstallments($type),
            'frequency' => $type,
            'due_day' => $this->faker->optional()->numberBetween(1, 28),
            'curriculum_id' => $this->faker->optional()->randomElement(Curriculum::pluck('id')->toArray()),
            'is_active' => $this->faker->boolean(85), // 85% active
            'is_default' => false,
            'auto_generate_invoices' => $this->faker->boolean(90),
            'setup_fee' => $this->faker->optional(0.3)->randomFloat(2, 10, 100),
            'discount_percentage' => $this->faker->optional(0.4)->randomFloat(2, 5, 25),
            'grace_period_days' => $this->faker->numberBetween(3, 14),
            'late_fee_amount' => $this->faker->optional(0.7)->randomFloat(2, 10, 50),
            'accepted_payment_methods' => $this->faker->randomElements(
                array_keys(PaymentPlan::getPaymentMethods()),
                $this->faker->numberBetween(2, 4)
            ),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function withDiscount(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_percentage' => $this->faker->randomFloat(2, 10, 30),
            'discount_valid_until' => $this->faker->dateTimeBetween('now', '+6 months'),
        ]);
    }

    public function forCurriculum(Curriculum $curriculum): static
    {
        return $this->state(fn (array $attributes) => [
            'curriculum_id' => $curriculum->id,
            'name' => $curriculum->name . ' - ' . $attributes['name'],
        ]);
    }
}