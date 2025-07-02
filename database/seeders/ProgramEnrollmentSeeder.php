<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\ChildProfile;
use App\Models\Curriculum;
use App\Models\PaymentPlan;
use App\Models\ProgramEnrollment;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class ProgramEnrollmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $childProfiles = ChildProfile::all();
        $currentAcademicYear = AcademicYear::where('is_current', true)->first();
        $previousAcademicYear = AcademicYear::where('end_date', '<', $currentAcademicYear->start_date)
            ->orderBy('end_date', 'desc')
            ->first();

        if ($childProfiles->isEmpty() || !$currentAcademicYear) {
            return;
        }

        // Use only the allowed enum values from your migration: 'active', 'inactive', 'completed', 'withdrawn'
        $statuses = ['active', 'inactive', 'completed', 'withdrawn'];

        foreach ($childProfiles as $childProfile) {
            // Calculate child's age
            $birthDate = $childProfile->date_of_birth;
            $age = Carbon::parse($birthDate)->age;

            // Determine appropriate curriculum based on age
            $curriculum = null;
            if ($age >= 6 && $age <= 11) {
                $curriculum = Curriculum::where('code', 'PEP')->first();
            } elseif ($age >= 12 && $age <= 14) {
                $curriculum = Curriculum::where('code', 'MSP')->first();
            } elseif ($age >= 15) {
                // Older students can be in HSP, IB, or SST
                $curriculum = Curriculum::whereIn('code', ['HSP', 'IB', 'SST'])->inRandomOrder()->first();
            }

            if (!$curriculum) {
                continue;
            }

            // Get a payment plan for this curriculum
            $paymentPlan = PaymentPlan::where('curriculum_id', $curriculum->id)
                ->inRandomOrder()
                ->first();

            if (!$paymentPlan) {
                continue; // Skip if no payment plan found
            }

            // Enroll in current academic year - use 'active' or 'inactive' for current year
            ProgramEnrollment::create([
                'child_profile_id' => $childProfile->id,
                'curriculum_id' => $curriculum->id,
                'academic_year_id' => $currentAcademicYear->id,
                'status' => $statuses[array_rand([0, 1])], // 'active' or 'inactive'
                'payment_plan_id' => $paymentPlan->id,
            ]);

            // Some children also have enrollments from previous year
            if ($previousAcademicYear && rand(0, 1)) {
                ProgramEnrollment::create([
                    'child_profile_id' => $childProfile->id,
                    'curriculum_id' => $curriculum->id,
                    'academic_year_id' => $previousAcademicYear->id,
                    'status' => $statuses[array_rand([2, 3])], // 'completed' or 'withdrawn' for previous year
                    'payment_plan_id' => $paymentPlan->id,
                ]);
            }
        }
    }
}