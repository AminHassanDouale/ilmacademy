<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\Subject;
use App\Models\TeacherProfile;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ExamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currentAcademicYear = AcademicYear::where('is_current', true)->first();
        $subjects = Subject::all();

        if (!$currentAcademicYear || $subjects->isEmpty()) {
            return;
        }

        // Use only the allowed enum values from your migration: 'midterm', 'final', 'quiz', 'assignment'
        $examTypes = ['quiz', 'midterm', 'final', 'assignment'];

        // Create 3 exam periods: beginning, middle, and end of the year
        $examPeriods = [
            [
                'name' => 'First Quarter Assessments',
                'start' => Carbon::parse($currentAcademicYear->start_date)->addMonths(1),
                'end' => Carbon::parse($currentAcademicYear->start_date)->addMonths(2),
                'types' => ['quiz', 'assignment']
            ],
            [
                'name' => 'Midterm Examinations',
                'start' => Carbon::parse($currentAcademicYear->start_date)->addMonths(4),
                'end' => Carbon::parse($currentAcademicYear->start_date)->addMonths(5),
                'types' => ['midterm', 'assignment'] // changed from 'project' to 'assignment'
            ],
            [
                'name' => 'Final Examinations',
                'start' => Carbon::parse($currentAcademicYear->end_date)->subMonths(2),
                'end' => Carbon::parse($currentAcademicYear->end_date)->subMonths(1),
                'types' => ['final', 'assignment'] // changed from 'project' to 'assignment'
            ],
        ];

        foreach ($subjects as $subject) {
            // Find teachers who teach this subject
            $teachers = TeacherProfile::whereHas('subjects', function ($query) use ($subject) {
                $query->where('subjects.id', $subject->id);
            })->get();

            if ($teachers->isEmpty()) {
                continue;
            }

            $teacher = $teachers->random();

            // Create exams for each period
            foreach ($examPeriods as $period) {
                // Random date within the exam period
                $examDate = Carbon::parse($period['start'])->addDays(rand(0, Carbon::parse($period['end'])->diffInDays($period['start'])));

                // Random exam type from the period's allowed types
                $examType = $period['types'][array_rand($period['types'])];

                // Create a descriptive title that includes the exam type and period
                $displayType = $examType === 'assignment' ? 'Project' : ucfirst($examType);

                Exam::create([
                    'subject_id' => $subject->id,
                    'teacher_profile_id' => $teacher->id,
                    'academic_year_id' => $currentAcademicYear->id,
                    'title' => "{$subject->name} {$displayType} - {$period['name']}",
                    'exam_date' => $examDate,
                    'type' => $examType, // This uses a valid enum value
                ]);
            }

            // Add some additional quizzes throughout the year
            $numAdditionalQuizzes = rand(1, 3);
            for ($i = 0; $i < $numAdditionalQuizzes; $i++) {
                $quizDate = Carbon::parse($currentAcademicYear->start_date)
                    ->addDays(rand(30, Carbon::parse($currentAcademicYear->end_date)->diffInDays($currentAcademicYear->start_date) - 30));

                Exam::create([
                    'subject_id' => $subject->id,
                    'teacher_profile_id' => $teacher->id,
                    'academic_year_id' => $currentAcademicYear->id,
                    'title' => "{$subject->name} Pop Quiz " . ($i + 1),
                    'exam_date' => $quizDate,
                    'type' => 'quiz',
                ]);
            }
        }
    }
}
