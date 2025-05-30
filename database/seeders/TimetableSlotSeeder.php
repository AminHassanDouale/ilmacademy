<?php

namespace Database\Seeders;

use App\Models\TimetableSlot;
use App\Models\TeacherProfile;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TimetableSlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $timeSlots = [
            ['08:00', '09:30'],
            ['09:45', '11:15'],
            ['11:30', '13:00'],
            ['14:00', '15:30'],
            ['15:45', '17:15'],
        ];

        // Get all teacher-subject relationships
        $teacherSubjects = DB::table('teacher_subject')
            ->join('teacher_profiles', 'teacher_subject.teacher_profile_id', '=', 'teacher_profiles.id')
            ->join('subjects', 'teacher_subject.subject_id', '=', 'subjects.id')
            ->select('teacher_subject.teacher_profile_id', 'teacher_subject.subject_id')
            ->get();

        if ($teacherSubjects->isEmpty()) {
            return;
        }

        // Create a reference date (we'll use today, but the actual date doesn't matter)
        $referenceDate = Carbon::today()->format('Y-m-d');

        // Create timetable slots
        foreach ($days as $day) {
            foreach ($timeSlots as $timeSlot) {
                // Use a subset of teacher-subject combinations for each slot
                $subsetSize = min($teacherSubjects->count(), rand(1, 3));
                $subset = $teacherSubjects->random($subsetSize);

                foreach ($subset as $teacherSubject) {
                    TimetableSlot::create([
                        'subject_id' => $teacherSubject->subject_id,
                        'teacher_profile_id' => $teacherSubject->teacher_profile_id,
                        'day' => $day,
                        'start_time' => $referenceDate . ' ' . $timeSlot[0] . ':00',
                        'end_time' => $referenceDate . ' ' . $timeSlot[1] . ':00',
                    ]);
                }
            }
        }
    }
}
