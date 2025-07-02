<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\ProgramEnrollment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if required models exist
        if (!class_exists(AcademicYear::class) || !class_exists(ProgramEnrollment::class)) {
            $this->command->info('Required models not found. Skipping invoice seeding.');
            return;
        }

        $currentAcademicYear = AcademicYear::where('is_current', true)->first();

        if (!$currentAcademicYear) {
            $this->command->info('No current academic year found. Creating one...');
            $currentAcademicYear = AcademicYear::create([
                'name' => '2024-2025',
                'start_date' => '2024-09-01',
                'end_date' => '2025-06-30',
                'is_current' => true,
            ]);
        }

        $programEnrollments = ProgramEnrollment::where('academic_year_id', $currentAcademicYear->id)
            ->with(['childProfile', 'curriculum', 'paymentPlan'])
            ->get();

        $adminUser = User::whereHas('roles', function($q) {
            $q->where('name', 'admin');
        })->first();

        if ($programEnrollments->isEmpty()) {
            $this->command->info('No program enrollments found for current academic year.');
            return;
        }

        $invoiceStatuses = [
            'draft' => 5,
            'sent' => 15,
            'partially_paid' => 25,
            'paid' => 45,
            'overdue' => 10,
        ];

        $invoiceCount = 0;

        foreach ($programEnrollments as $enrollment) {
            if (!$enrollment->childProfile || !$enrollment->curriculum) {
                continue;
            }

            // Determine payment frequency and number of invoices to create
            $paymentFrequency = 1; // Default to 1 payment
            $baseAmount = 1000; // Default amount

            if ($enrollment->paymentPlan) {
                $paymentFrequency = match($enrollment->paymentPlan->type) {
                    'annual' => 1,
                    'semester' => 2,
                    'quarterly' => 4,
                    'monthly' => 10, // 10 months of the school year
                    default => 1,
                };
                $baseAmount = $enrollment->paymentPlan->amount;
            }

            // Create invoices based on payment frequency
            for ($i = 0; $i < $paymentFrequency; $i++) {
                // First invoice date is start of academic year
                $invoiceDate = Carbon::parse($currentAcademicYear->start_date)
                    ->addMonths($i * (10 / $paymentFrequency));
                $dueDate = (clone $invoiceDate)->addDays(14);

                // Don't create future invoices beyond today for more realistic data
                if ($invoiceDate > now()) {
                    continue;
                }

                // Determine status using weighted randomization
                $statusRand = rand(1, 100);
                $cumulativeWeight = 0;
                $status = 'draft';

                foreach ($invoiceStatuses as $stat => $weight) {
                    $cumulativeWeight += $weight;
                    if ($statusRand <= $cumulativeWeight) {
                        $status = $stat;
                        break;
                    }
                }

                // Adjust status based on due date logic
                if ($status === 'sent' && $dueDate < now()) {
                    $status = 'overdue';
                }

                // Set paid_date if paid
                $paidDate = ($status === 'paid') ? (clone $dueDate)->subDays(rand(0, 10)) : null;

                // Create the invoice
                try {
                    $invoice = Invoice::create([
                        'invoice_number' => Invoice::generateInvoiceNumber(),
                        'amount' => $baseAmount,
                        'invoice_date' => $invoiceDate,
                        'due_date' => $dueDate,
                        'paid_date' => $paidDate,
                        'status' => $status,
                        'description' => "Tuition fee for {$enrollment->curriculum->name} - " .
                                        match($paymentFrequency) {
                                            1 => 'Full Year',
                                            2 => ($i === 0 ? 'First' : 'Second') . ' Semester',
                                            4 => 'Quarter ' . ($i + 1),
                                            10 => 'Month ' . ($i + 1),
                                            default => 'Payment ' . ($i + 1),
                                        },
                        'child_profile_id' => $enrollment->childProfile->id,
                        'academic_year_id' => $currentAcademicYear->id,
                        'curriculum_id' => $enrollment->curriculum->id,
                        'program_enrollment_id' => $enrollment->id,
                        'created_by' => $adminUser ? $adminUser->id : null,
                        'notes' => fake()->boolean(20) ? fake()->sentence() : null,
                    ]);

                    $invoiceCount++;
                } catch (\Exception $e) {
                    $this->command->error("Failed to create invoice: " . $e->getMessage());
                }
            }
        }

        $this->command->info("Created {$invoiceCount} invoices successfully!");
    }
}