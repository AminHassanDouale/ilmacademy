<?php

namespace Database\Seeders;

use App\Models\Subject;
use App\Models\TeacherProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeacherSubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teachers = TeacherProfile::all();
        $subjects = Subject::all();

        if ($teachers->isEmpty() || $subjects->isEmpty()) {
            return;
        }

        foreach ($teachers as $teacher) {
            // Determine which subjects match the teacher's specialization
            $matchingSubjects = $subjects->filter(function ($subject) use ($teacher) {
                return stripos($subject->name, $teacher->specialization) !== false ||
                       stripos($subject->code, substr($teacher->specialization, 0, 3)) !== false;
            });

            // If no matching subjects found, assign random ones
            if ($matchingSubjects->isEmpty()) {
                $matchingSubjects = $subjects->random(rand(2, 4));
            }

            // Assign subjects to teacher
            foreach ($matchingSubjects as $subject) {
                // Check if the relationship already exists
                $exists = DB::table('teacher_subject')
                    ->where('teacher_profile_id', $teacher->id)
                    ->where('subject_id', $subject->id)
                    ->exists();

                if (!$exists) {
                    DB::table('teacher_subject')->insert([
                        'teacher_profile_id' => $teacher->id,
                        'subject_id' => $subject->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
