<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PaymentPlan;
use App\Models\Curriculum;
use Illuminate\Support\Facades\DB;

class PaymentPlanSeeder extends Seeder
{
    public function run(): void
    {
        $curricula = Curriculum::all();

        // Create general payment plans (not tied to specific curriculum)
        $generalPlans = [
            [
                'name' => 'Standard Monthly Plan',
                'description' => 'Standard monthly payment option for all programs',
                'type' => 'monthly',
                'amount' => 150.00,
                'currency' => 'USD',
                'installments' => 12,
                'frequency' => 'monthly',
                'due_day' => 1,
                'curriculum_id' => null,
                'is_active' => true,
                'is_default' => true,
                'auto_generate_invoices' => true,
                'grace_period_days' => 7,
                'late_fee_amount' => 25.00,
                'payment_instructions' => 'Payment due on the 1st of each month',
                'accepted_payment_methods' => ['bank_transfer', 'credit_card', 'debit_card', 'online_payment'],
            ],
            [
                'name' => 'Quarterly Savings Plan',
                'description' => 'Pay quarterly and save 5% compared to monthly payments',
                'type' => 'quarterly',
                'amount' => 427.50, // 3 months at $150 - 5% discount
                'currency' => 'USD',
                'installments' => 4,
                'frequency' => 'quarterly',
                'due_day' => 1,
                'curriculum_id' => null,
                'is_active' => true,
                'is_default' => false,
                'auto_generate_invoices' => true,
                'discount_percentage' => 5.00,
                'grace_period_days' => 14,
                'late_fee_percentage' => 2.50,
                'payment_instructions' => 'Payment due quarterly on the 1st day of the quarter',
                'accepted_payment_methods' => ['bank_transfer', 'credit_card', 'check'],
            ],
            [
                'name' => 'Annual Discount Plan',
                'description' => 'Pay annually and save 10% - Best Value!',
                'type' => 'annual',
                'amount' => 1620.00, // 12 months at $150 - 10% discount
                'currency' => 'USD',
                'installments' => 1,
                'frequency' => 'annual',
                'due_day' => 1,
                'curriculum_id' => null,
                'is_active' => true,
                'is_default' => false,
                'auto_generate_invoices' => true,
                'discount_percentage' => 10.00,
                'grace_period_days' => 30,
                'late_fee_amount' => 100.00,
                'payment_instructions' => 'Annual payment due at enrollment',
                'accepted_payment_methods' => ['bank_transfer', 'check'],
                'terms_and_conditions' => 'Annual payments are non-refundable after 30 days',
            ],
        ];

        foreach ($generalPlans as $planData) {
            PaymentPlan::create($planData);
        }

        // Create curriculum-specific payment plans
        foreach ($curricula as $curriculum) {
            $curriculumPlans = $this->getCurriculumSpecificPlans($curriculum);

            foreach ($curriculumPlans as $planData) {
                PaymentPlan::create($planData);
            }
        }

        // Create some special promotional plans
        $this->createPromotionalPlans();
    }

    private function getCurriculumSpecificPlans($curriculum): array
    {
        $baseAmount = $curriculum->price ?? 200.00;
        $curriculumName = $curriculum->name;

        return [
            [
                'name' => "{$curriculumName} - Monthly",
                'description' => "Monthly payment plan specifically for {$curriculumName}",
                'type' => 'monthly',
                'amount' => $baseAmount * 0.1, // 10% of total per month
                'currency' => $curriculum->currency ?? 'USD',
                'installments' => 10, // 10-month program
                'frequency' => 'monthly',
                'due_day' => 15,
                'curriculum_id' => $curriculum->id,
                'is_active' => true,
                'is_default' => true,
                'auto_generate_invoices' => true,
                'setup_fee' => $baseAmount * 0.05, // 5% setup fee
                'grace_period_days' => 5,
                'late_fee_amount' => 15.00,
                'payment_instructions' => "Monthly payment for {$curriculumName} due on the 15th",
                'accepted_payment_methods' => ['bank_transfer', 'credit_card', 'debit_card'],
                'metadata' => [
                    'curriculum_duration' => $curriculum->duration_months ?? 10,
                    'curriculum_level' => $curriculum->level ?? 'beginner',
                ],
            ],
            [
                'name' => "{$curriculumName} - One-Time",
                'description' => "Full payment option for {$curriculumName} with discount",
                'type' => 'one-time',
                'amount' => $baseAmount * 0.85, // 15% discount for full payment
                'currency' => $curriculum->currency ?? 'USD',
                'installments' => 1,
                'frequency' => 'one-time',
                'curriculum_id' => $curriculum->id,
                'is_active' => true,
                'is_default' => false,
                'auto_generate_invoices' => true,
                'discount_percentage' => 15.00,
                'grace_period_days' => 14,
                'payment_instructions' => "Full payment for {$curriculumName} due at enrollment",
                'accepted_payment_methods' => ['bank_transfer', 'credit_card', 'check'],
                'terms_and_conditions' => 'Full payment plans include 15% discount and priority enrollment',
                'metadata' => [
                    'curriculum_duration' => $curriculum->duration_months ?? 10,
                    'curriculum_level' => $curriculum->level ?? 'beginner',
                    'includes_materials' => true,
                ],
            ],
        ];
    }

    private function createPromotionalPlans(): void
    {
        $promotionalPlans = [
            [
                'name' => 'Early Bird Special',
                'description' => 'Limited time offer - 20% off first 3 months',
                'type' => 'monthly',
                'amount' => 120.00, // $150 - 20% discount
                'currency' => 'USD',
                'installments' => 3,
                'frequency' => 'monthly',
                'due_day' => 1,
                'curriculum_id' => null,
                'is_active' => true,
                'is_default' => false,
                'auto_generate_invoices' => true,
                'discount_percentage' => 20.00,
                'discount_valid_until' => now()->addMonths(2),
                'grace_period_days' => 7,
                'late_fee_amount' => 20.00,
                'payment_instructions' => 'Early bird promotional rate - limited time only',
                'accepted_payment_methods' => ['bank_transfer', 'credit_card', 'debit_card'],
                'terms_and_conditions' => 'Promotional rate applies to first 3 months only. Standard rates apply thereafter.',
                'metadata' => [
                    'promotion_code' => 'EARLY2025',
                    'promotion_type' => 'early_bird',
                    'max_enrollments' => 50,
                ],
            ],
            [
                'name' => 'Family Discount Plan',
                'description' => 'Special rate for families with multiple children',
                'type' => 'monthly',
                'amount' => 135.00, // $150 - 10% family discount
                'currency' => 'USD',
                'installments' => 12,
                'frequency' => 'monthly',
                'due_day' => 1,
                'curriculum_id' => null,
                'is_active' => true,
                'is_default' => false,
                'auto_generate_invoices' => true,
                'discount_percentage' => 10.00,
                'grace_period_days' => 10,
                'late_fee_amount' => 20.00,
                'payment_instructions' => 'Family discount applies to 2nd child and beyond',
                'accepted_payment_methods' => ['bank_transfer', 'credit_card', 'debit_card', 'online_payment'],
                'terms_and_conditions' => 'Family discount requires minimum 2 children enrolled simultaneously',
                'metadata' => [
                    'discount_type' => 'family',
                    'min_children' => 2,
                    'max_discount_children' => 5,
                ],
            ],
            [
                'name' => 'Student Financial Aid',
                'description' => 'Reduced rate payment plan for qualifying families',
                'type' => 'monthly',
                'amount' => 75.00, // 50% of standard rate
                'currency' => 'USD',
                'installments' => 12,
                'frequency' => 'monthly',
                'due_day' => 1,
                'curriculum_id' => null,
                'is_active' => true,
                'is_default' => false,
                'auto_generate_invoices' => true,
                'discount_percentage' => 50.00,
                'grace_period_days' => 14,
                'late_fee_amount' => 10.00,
                'payment_instructions' => 'Financial aid payment plan - income verification required',
                'accepted_payment_methods' => ['bank_transfer', 'cash', 'check'],
                'terms_and_conditions' => 'Financial aid requires annual income verification and application approval',
                'metadata' => [
                    'aid_type' => 'need_based',
                    'requires_application' => true,
                    'renewal_required' => 'annually',
                ],
            ],
        ];

        foreach ($promotionalPlans as $planData) {
            PaymentPlan::create($planData);
        }
    }
}
