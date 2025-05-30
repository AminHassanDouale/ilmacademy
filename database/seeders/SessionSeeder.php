<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\Session;
use App\Models\TimetableSlot;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SessionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rooms = Room::all();
        $timetableSlots = TimetableSlot::all();

        if ($timetableSlots->isEmpty() || $rooms->isEmpty()) {
            return;
        }

        // Use only the allowed enum values from your migration: 'live', 'recorded'
        // Map your session types to the allowed enum values
        $sessionTypeMap = [
            'in-person' => 'live',
            'online' => 'live',     // Both in-person and online are "live"
            'hybrid' => 'live',
        ];

        $sessionTypes = array_keys($sessionTypeMap);

        // Create sessions for the next 4 weeks based on timetable slots
        $startDate = Carbon::now()->startOfWeek();

        for ($week = 0; $week < 4; $week++) {
            $weekStartDate = (clone $startDate)->addWeeks($week);

            foreach ($timetableSlots as $slot) {
                // Get day index (0 = Monday, 1 = Tuesday, etc.)
                $dayIndex = match($slot->day) {
                    'Monday' => 0,
                    'Tuesday' => 1,
                    'Wednesday' => 2,
                    'Thursday' => 3,
                    'Friday' => 4,
                    'Saturday' => 5,
                    'Sunday' => 6,
                    default => 0,
                };

                $sessionDate = (clone $weekStartDate)->addDays($dayIndex);

                // Select a session format (in-person, online, hybrid)
                $sessionFormat = $sessionTypes[array_rand($sessionTypes)];

                // Map this to the corresponding database enum value
                $sessionType = $sessionTypeMap[$sessionFormat];

                // For in-person or hybrid sessions, assign a room
                $room = ($sessionFormat === 'online') ? null : $rooms->random();

                // Fix time conversion
                $startTimeStr = is_string($slot->start_time) ? $slot->start_time : (is_object($slot->start_time) ? $slot->start_time->format('H:i:s') : '08:00:00');
                $endTimeStr = is_string($slot->end_time) ? $slot->end_time : (is_object($slot->end_time) ? $slot->end_time->format('H:i:s') : '09:30:00');

                $startTime = (clone $sessionDate)->setTimeFromTimeString($startTimeStr);
                $endTime = (clone $sessionDate)->setTimeFromTimeString($endTimeStr);

                Session::create([
                    'subject_id' => $slot->subject_id,
                    'teacher_profile_id' => $slot->teacher_profile_id,
                    'room_id' => $room ? $room->id : null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'link' => ($sessionFormat === 'online' || $sessionFormat === 'hybrid')
                            ? 'https://meet.zoom.us/' . str_replace('-', '', fake()->uuid())
                            : null,
                    'type' => $sessionType, // Use the mapped enum value
                ]);
            }
        }
    }
}
