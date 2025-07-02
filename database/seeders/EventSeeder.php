<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use App\Models\AcademicYear;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get a user to be the creator (preferably an admin)
        $creator = User::first();

        if (!$creator) {
            $this->command->warn('No users found. Please create users first.');
            return;
        }

        // Get current academic year if available
        $academicYear = AcademicYear::where('is_current', true)->first()
                       ?? AcademicYear::first();

        $now = Carbon::now();

        $events = [
            [
                'title' => 'Welcome Assembly',
                'description' => 'Welcome assembly for new students and parents.',
                'type' => 'academic',
                'start_date' => $now->copy()->addDays(5)->setTime(9, 0),
                'end_date' => $now->copy()->addDays(5)->setTime(11, 0),
                'is_all_day' => false,
                'location' => 'Main Auditorium',
                'status' => 'active',
            ],
            [
                'title' => 'Parent-Teacher Conference',
                'description' => 'Quarterly parent-teacher conference meetings.',
                'type' => 'meeting',
                'start_date' => $now->copy()->addDays(10)->setTime(14, 0),
                'end_date' => $now->copy()->addDays(10)->setTime(17, 0),
                'is_all_day' => false,
                'location' => 'Conference Room A',
                'status' => 'active',
            ],
            [
                'title' => 'Mid-term Examinations',
                'description' => 'Mid-term examinations for all classes.',
                'type' => 'exam',
                'start_date' => $now->copy()->addDays(20)->setTime(8, 0),
                'end_date' => $now->copy()->addDays(25)->setTime(15, 0),
                'is_all_day' => true,
                'location' => 'Various Classrooms',
                'status' => 'active',
            ],
            [
                'title' => 'Spring Break',
                'description' => 'Spring break holiday - no classes.',
                'type' => 'holiday',
                'start_date' => $now->copy()->addDays(30)->startOfDay(),
                'end_date' => $now->copy()->addDays(37)->endOfDay(),
                'is_all_day' => true,
                'location' => null,
                'status' => 'active',
            ],
            [
                'title' => 'Science Fair',
                'description' => 'Annual science fair exhibition.',
                'type' => 'event',
                'start_date' => $now->copy()->addDays(45)->setTime(10, 0),
                'end_date' => $now->copy()->addDays(45)->setTime(16, 0),
                'is_all_day' => false,
                'location' => 'Science Building',
                'status' => 'active',
            ],
            [
                'title' => 'Assignment Deadline - Mathematics',
                'description' => 'Final submission deadline for mathematics assignments.',
                'type' => 'deadline',
                'start_date' => $now->copy()->addDays(15)->setTime(23, 59),
                'end_date' => $now->copy()->addDays(15)->setTime(23, 59),
                'is_all_day' => false,
                'location' => 'Online Submission',
                'status' => 'active',
            ],
            [
                'title' => 'Staff Meeting',
                'description' => 'Monthly staff meeting to discuss curriculum updates.',
                'type' => 'meeting',
                'start_date' => $now->copy()->addDays(7)->setTime(15, 30),
                'end_date' => $now->copy()->addDays(7)->setTime(17, 0),
                'is_all_day' => false,
                'location' => 'Staff Room',
                'status' => 'active',
            ],
            [
                'title' => 'Cultural Day',
                'description' => 'Celebrate different cultures with performances and food.',
                'type' => 'event',
                'start_date' => $now->copy()->addDays(60)->setTime(9, 0),
                'end_date' => $now->copy()->addDays(60)->setTime(15, 0),
                'is_all_day' => false,
                'location' => 'School Grounds',
                'status' => 'active',
            ],
        ];

        foreach ($events as $eventData) {
            Event::create([
                ...$eventData,
                'created_by' => $creator->id,
                'academic_year_id' => $academicYear?->id,
            ]);
        }

        $this->command->info('Events seeded successfully!');
    }
}
