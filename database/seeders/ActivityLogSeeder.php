<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\ChildProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProgramEnrollment;
use Illuminate\Database\Seeder;

class ActivityLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        // Log authentication activities
        foreach ($users as $user) {
            // Login activities
            $numLogins = rand(1, 10);
            for ($i = 0; $i < $numLogins; $i++) {
                ActivityLog::logActivity(
                    $user->id,
                    'login',
                    "User {$user->name} logged in",
                    $user,
                    ['ip' => fake()->ipv4()]
                );
            }

            // Logout activities
            $numLogouts = rand(1, $numLogins);
            for ($i = 0; $i < $numLogouts; $i++) {
                ActivityLog::logActivity(
                    $user->id,
                    'logout',
                    "User {$user->name} logged out",
                    $user,
                    ['ip' => fake()->ipv4()]
                );
            }
        }

        // Log payment activities
        $payments = Payment::all();
        foreach ($payments as $payment) {
            // Get student name safely
            $studentName = 'Unknown';
            try {
                if ($payment->child_profile_id) {
                    $childProfile = ChildProfile::find($payment->child_profile_id);
                    if ($childProfile && $childProfile->user) {
                        $studentName = $childProfile->user->name;
                    } elseif ($childProfile && $childProfile->parentProfile && $childProfile->parentProfile->user) {
                        $studentName = $childProfile->parentProfile->user->name . "'s child";
                    }
                }
            } catch (\Exception $e) {
                // If any errors occur, just use 'Unknown'
                $studentName = 'Unknown';
            }

            ActivityLog::logActivity(
                $payment->created_by,
                'payment',
                "Payment of {$payment->amount} processed for student",
                $payment,
                [
                    'ip' => fake()->ipv4(),
                    'method' => $payment->payment_method ?? 'Not specified',
                    'student_name' => $studentName,
                ]
            );
        }

        // Log invoice activities
        $invoices = Invoice::all();
        foreach ($invoices as $invoice) {
            // Get student name safely
            $studentName = 'Unknown';
            try {
                if ($invoice->child_profile_id) {
                    $childProfile = ChildProfile::find($invoice->child_profile_id);
                    if ($childProfile && $childProfile->user) {
                        $studentName = $childProfile->user->name;
                    } elseif ($childProfile && $childProfile->parentProfile && $childProfile->parentProfile->user) {
                        $studentName = $childProfile->parentProfile->user->name . "'s child";
                    }
                }
            } catch (\Exception $e) {
                // If any errors occur, just use 'Unknown'
                $studentName = 'Unknown';
            }

            ActivityLog::logActivity(
                $invoice->created_by,
                'create',
                "Invoice #{$invoice->invoice_number} created",
                $invoice,
                [
                    'ip' => fake()->ipv4(),
                    'amount' => $invoice->amount,
                    'student_name' => $studentName,
                ]
            );

            if ($invoice->status === 'paid') {
                ActivityLog::logActivity(
                    $invoice->created_by,
                    'update',
                    "Invoice #{$invoice->invoice_number} marked as paid",
                    $invoice,
                    [
                        'ip' => fake()->ipv4(),
                        'previous_status' => 'sent',
                        'new_status' => 'paid',
                    ]
                );
            }
        }

        // Log enrollment activities
        $enrollments = ProgramEnrollment::all();
        foreach ($enrollments as $enrollment) {
            $adminUser = User::role('admin')->first();

            // Get student name safely
            $studentName = 'Unknown';
            $academicYearName = 'Unknown';
            $curriculumName = 'Unknown';

            try {
                if ($enrollment->childProfile && $enrollment->childProfile->user) {
                    $studentName = $enrollment->childProfile->user->name;
                } elseif ($enrollment->childProfile && $enrollment->childProfile->parentProfile && $enrollment->childProfile->parentProfile->user) {
                    $studentName = $enrollment->childProfile->parentProfile->user->name . "'s child";
                }

                if ($enrollment->academicYear) {
                    $academicYearName = $enrollment->academicYear->name;
                }

                if ($enrollment->curriculum) {
                    $curriculumName = $enrollment->curriculum->name;
                }
            } catch (\Exception $e) {
                // Default values already set
            }

            ActivityLog::logActivity(
                $adminUser ? $adminUser->id : null,
                'enrollment',
                "Student enrolled in {$curriculumName} program",
                $enrollment,
                [
                    'ip' => fake()->ipv4(),
                    'student_name' => $studentName,
                    'academic_year' => $academicYearName,
                ]
            );
        }

        // Log child profile activities
        $childProfiles = ChildProfile::all();
        foreach ($childProfiles as $childProfile) {
            if (!$childProfile->parentProfile || !$childProfile->parentProfile->user) {
                continue;
            }

            $parentUser = $childProfile->parentProfile->user;

            ActivityLog::logActivity(
                $parentUser->id,
                'create',
                "Child profile created",
                $childProfile,
                [
                    'ip' => fake()->ipv4(),
                    'parent_name' => $parentUser->name,
                ]
            );

            if (fake()->boolean(40)) {
                ActivityLog::logActivity(
                    $parentUser->id,
                    'update',
                    "Child profile updated",
                    $childProfile,
                    [
                        'ip' => fake()->ipv4(),
                        'parent_name' => $parentUser->name,
                        'updated_fields' => fake()->randomElements(['medical_information', 'special_needs', 'additional_needs'], rand(1, 3)),
                    ]
                );
            }
        }
    }
}
