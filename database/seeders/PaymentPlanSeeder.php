<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentPlan;
use App\Models\Curriculum;
use Illuminate\Support\Facades\DB;

class PaymentPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Safely clear existing payment plans
        $this->clearPaymentPlans();

        // Get all curricula
        $curricula = Curriculum::all();

        if ($curricula->isEmpty()) {
            $this->command->warn('No curricula found. Please run CurriculumSeeder first.');
            return;
        }

        // Create payment plans for each curriculum
        foreach ($curricula as $curriculum) {
            $this->createCurriculumPlans($curriculum);
        }

        // Create general payment plans
        $this->createGeneralPlans();

        $this->command->info('PaymentPlan seeder completed successfully!');
        $this->command->info('Created ' . PaymentPlan::count() . ' payment plans.');
    }

    /**
     * Safely clear existing payment plans by handling foreign key constraints
     */
    private function clearPaymentPlans(): void
    {
        try {
            // Option 1: Try to delete all payment plans (respects foreign keys)
            PaymentPlan::query()->delete();
        } catch (\Exception $e) {
            // Option 2: If delete fails, disable foreign key checks temporarily
            $this->command->warn('Standard delete failed, using foreign key disable method...');

            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            PaymentPlan::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Create payment plans for a specific curriculum
     */
    private function createCurriculumPlans(Curriculum $curriculum): void
    {
        $plans = [
            [
                'name' => 'Monthly Plan - ' . $curriculum->name,
                'type' => 'monthly',
                'amount' => 299.00,
                'description' => 'Monthly payment plan for ' . $curriculum->name . '. Spread your payments over 12 months.',
                'installments' => 12,
                'frequency' => 'monthly',
            ],
            [
                'name' => 'Quarterly Plan - ' . $curriculum->name,
                'type' => 'quarterly',
                'amount' => 850.00,
                'description' => 'Quarterly payment plan for ' . $curriculum->name . '. Pay every 3 months.',
                'installments' => 4,
                'frequency' => 'quarterly',
            ],
            [
                'name' => 'Semi-Annual Plan - ' . $curriculum->name,
                'type' => 'semi-annual',
                'amount' => 1650.00,
                'description' => 'Semi-annual payment plan for ' . $curriculum->name . '. Pay twice a year.',
                'installments' => 2,
                'frequency' => 'semi-annual',
            ],
            [
                'name' => 'Annual Plan - ' . $curriculum->name,
                'type' => 'annual',
                'amount' => 3200.00,
                'description' => 'Annual payment plan for ' . $curriculum->name . '. Pay once per year.',
                'installments' => 1,
                'frequency' => 'annual',
            ],
            [
                'name' => 'Full Payment - ' . $curriculum->name,
                'type' => 'one-time',
                'amount' => 2999.00,
                'description' => 'Full payment upfront for ' . $curriculum->name . '. Save $201 with this option!',
                'installments' => 1,
                'frequency' => 'one-time',
            ],
        ];

        foreach ($plans as $planData) {
            PaymentPlan::create(array_merge($planData, [
                'currency' => 'USD',
                'curriculum_id' => $curriculum->id,
                'is_active' => true,
            ]));
        }
    }

    /**
     * Create general payment plans (not tied to specific curriculum)
     */
    private function createGeneralPlans(): void
    {
        $generalPlans = [
            [
                'name' => 'Basic Monthly',
                'type' => 'monthly',
                'amount' => 199.00,
                'description' => 'Basic monthly payment plan - perfect for getting started.',
                'installments' => 12,
                'frequency' => 'monthly',
            ],
            [
                'name' => 'Standard Quarterly',
                'type' => 'quarterly',
                'amount' => 580.00,
                'description' => 'Standard quarterly payment plan - balance convenience and savings.',
                'installments' => 4,
                'frequency' => 'quarterly',
            ],
            [
                'name' => 'Premium Semi-Annual',
                'type' => 'semi-annual',
                'amount' => 1100.00,
                'description' => 'Premium semi-annual plan - great balance of convenience and savings.',
                'installments' => 2,
                'frequency' => 'semi-annual',
            ],
            [
                'name' => 'Premium Annual',
                'type' => 'annual',
                'amount' => 2200.00,
                'description' => 'Premium annual payment plan - best value option.',
                'installments' => 1,
                'frequency' => 'annual',
            ],
            [
                'name' => 'Flexible One-Time',
                'type' => 'one-time',
                'amount' => 2099.00,
                'description' => 'Flexible one-time payment - immediate access with maximum savings.',
                'installments' => 1,
                'frequency' => 'one-time',
            ],
            [
                'name' => 'Student Discount Monthly',
                'type' => 'monthly',
                'amount' => 149.00,
                'description' => 'Special monthly rate for students (ID verification required).',
                'installments' => 12,
                'frequency' => 'monthly',
            ],
            [
                'name' => 'Enterprise Annual',
                'type' => 'annual',
                'amount' => 4500.00,
                'description' => 'Enterprise annual plan with premium features and support.',
                'installments' => 1,
                'frequency' => 'annual',
            ],
            [
                'name' => 'Trial Monthly',
                'type' => 'monthly',
                'amount' => 99.00,
                'description' => 'Trial monthly plan for first-time users.',
                'installments' => 3,
                'frequency' => 'monthly',
            ],
        ];

        foreach ($generalPlans as $planData) {
            PaymentPlan::create(array_merge($planData, [
                'currency' => 'USD',
                'curriculum_id' => null,
                'is_active' => true,
            ]));
        }
    }
}
