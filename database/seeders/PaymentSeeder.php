<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::role('admin')->first();
        $paymentMethods = ['credit_card', 'bank_transfer', 'cash', 'check', 'online_payment'];

        // Process paid and partially paid invoices
        $invoices = Invoice::whereIn('status', ['paid', 'partially_paid'])->get();

        if ($invoices->isEmpty()) {
            return;
        }

        foreach ($invoices as $invoice) {
            $paymentAmount = 0;
            $status = 'completed';

            if ($invoice->status === 'paid') {
                // Full payment
                $paymentAmount = $invoice->amount;
                $paymentDate = $invoice->paid_date ?? Carbon::parse($invoice->due_date)->subDays(rand(1, 14));
            } else {
                // Partial payment
                $paymentAmount = $invoice->amount * rand(30, 70) / 100; // 30-70% of total
                $paymentDate = Carbon::parse($invoice->invoice_date)->addDays(rand(1, 30));
            }

            // Create the payment
            Payment::create([
                'amount' => $paymentAmount,
                'payment_date' => $paymentDate,
                'due_date' => $invoice->due_date,
                'status' => $status,
                'description' => "Payment for invoice #{$invoice->invoice_number}",
                'reference_number' => strtoupper(fake()->bothify('PAY-####-????')),
                'child_profile_id' => $invoice->child_profile_id,
                'academic_year_id' => $invoice->academic_year_id,
                'curriculum_id' => $invoice->curriculum_id,
                'invoice_id' => $invoice->id,
                'created_by' => $adminUser ? $adminUser->id : null,
                'payment_method' => fake()->randomElement($paymentMethods),
                'transaction_id' => strtoupper(fake()->bothify('TXN-######-???')),
                'notes' => fake()->boolean(20) ? fake()->sentence() : null,
            ]);

            // For some partially paid invoices, add a second payment
            if ($invoice->status === 'partially_paid' && fake()->boolean(40)) {
                $secondPaymentAmount = $invoice->amount * rand(10, 20) / 100; // Additional 10-20%
                $secondPaymentDate = Carbon::parse($paymentDate)->addDays(rand(10, 30));

                // Only create if payment date is not in the future
                if ($secondPaymentDate <= now()) {
                    Payment::create([
                        'amount' => $secondPaymentAmount,
                        'payment_date' => $secondPaymentDate,
                        'due_date' => $invoice->due_date,
                        'status' => 'completed',
                        'description' => "Additional payment for invoice #{$invoice->invoice_number}",
                        'reference_number' => strtoupper(fake()->bothify('PAY-####-????')),
                        'child_profile_id' => $invoice->child_profile_id,
                        'academic_year_id' => $invoice->academic_year_id,
                        'curriculum_id' => $invoice->curriculum_id,
                        'invoice_id' => $invoice->id,
                        'created_by' => $adminUser ? $adminUser->id : null,
                        'payment_method' => fake()->randomElement($paymentMethods),
                        'transaction_id' => strtoupper(fake()->bothify('TXN-######-???')),
                        'notes' => fake()->boolean(20) ? fake()->sentence() : null,
                    ]);
                }
            }
        }
    }
}
