<?php

namespace Database\Seeders;

use App\Models\ChildProfile;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\SubjectEnrollment;
use Illuminate\Database\Seeder;

class ExamResultSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pastExams = Exam::where('exam_date', '<', now())->get();

        if ($pastExams->isEmpty()) {
            return;
        }

        foreach ($pastExams as $exam) {
            // Find students enrolled in this subject
            $subjectEnrollments = SubjectEnrollment::whereHas('subject', function ($query) use ($exam) {
                $query->where('id', $exam->subject_id);
            })->get();

            foreach ($subjectEnrollments as $enrollment) {
                // Get the child profile for this enrollment
                $childProfile = ChildProfile::whereHas('programEnrollments', function ($query) use ($enrollment) {
                    $query->where('id', $enrollment->program_enrollment_id);
                })->first();

                if (!$childProfile) {
                    continue;
                }

                // Generate a score based on exam type
                $score = match($exam->type) {
                    'quiz' => rand(60, 100),
                    'midterm' => rand(50, 95),
                    'final' => rand(40, 98),
                    'project' => rand(70, 100),
                    'assignment' => rand(75, 100),
                    default => rand(50, 100),
                };

                // Generate remarks based on score
                $remarks = match(true) {
                    $score >= 90 => fake()->randomElement([
                        'Excellent work!',
                        'Outstanding performance',
                        'Exceptional understanding of the material',
                    ]),
                    $score >= 80 => fake()->randomElement([
                        'Very good work',
                        'Strong performance',
                        'Good understanding of concepts',
                    ]),
                    $score >= 70 => fake()->randomElement([
                        'Good effort',
                        'Satisfactory work',
                        'Adequate understanding of the material',
                    ]),
                    $score >= 60 => fake()->randomElement([
                        'Passing, but needs improvement',
                        'Basic understanding of concepts',
                        'More practice needed',
                    ]),
                    default => fake()->randomElement([
                        'Needs significant improvement',
                        'Please schedule a tutoring session',
                        'Additional study required',
                    ]),
                };

                ExamResult::create([
                    'exam_id' => $exam->id,
                    'child_profile_id' => $childProfile->id,
                    'score' => $score,
                    'remarks' => $remarks,
                ]);
            }
        }
    }
}
