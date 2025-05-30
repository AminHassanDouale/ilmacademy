<?php

namespace Database\Seeders;

use App\Models\ProgramEnrollment;
use App\Models\Subject;
use App\Models\SubjectEnrollment;
use Illuminate\Database\Seeder;

class SubjectEnrollmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $programEnrollments = ProgramEnrollment::with('curriculum')->get();

        if ($programEnrollments->isEmpty()) {
            return;
        }

        foreach ($programEnrollments as $programEnrollment) {
            // Get subjects for this curriculum
            $subjects = Subject::where('curriculum_id', $programEnrollment->curriculum_id)->get();

            if ($subjects->isEmpty()) {
                continue;
            }

            // Enroll student in all subjects for their curriculum
            foreach ($subjects as $subject) {
                SubjectEnrollment::create([
                    'program_enrollment_id' => $programEnrollment->id,
                    'subject_id' => $subject->id,
                ]);
            }
        }
    }
}
