<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\ChildProfile;
use App\Models\Session;
use App\Models\SubjectEnrollment;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get past sessions (sessions that have already occurred)
        $pastSessions = Session::where('start_time', '<', now())->get();

        if ($pastSessions->isEmpty()) {
            return;
        }

        foreach ($pastSessions as $session) {
            // Find students enrolled in this subject
            $subjectEnrollments = SubjectEnrollment::whereHas('subject', function ($query) use ($session) {
                $query->where('id', $session->subject_id);
            })->get();

            foreach ($subjectEnrollments as $enrollment) {
                // Get the child profile for this enrollment
                $childProfile = ChildProfile::whereHas('programEnrollments', function ($query) use ($enrollment) {
                    $query->where('id', $enrollment->program_enrollment_id);
                })->first();

                if (!$childProfile) {
                    continue;
                }

                // Check if attendance record already exists for this combination
                $existingAttendance = Attendance::where('session_id', $session->id)
                    ->where('child_profile_id', $childProfile->id)
                    ->exists();

                // Skip if an attendance record already exists
                if ($existingAttendance) {
                    continue;
                }

                // Determine attendance status with some randomness
                // Most students are present, some are late, fewer are absent
                $rand = rand(1, 100);
                $status = match(true) {
                    $rand <= 70 => 'present',
                    $rand <= 85 => 'late',
                    $rand <= 95 => 'absent',
                    default => 'excused',
                };

                // Add remarks for some records
                $remarks = null;
                if ($status === 'excused') {
                    $remarks = fake()->randomElement([
                        'Medical appointment',
                        'Family emergency',
                        'School event',
                        'Parent note provided'
                    ]);
                } elseif ($status === 'absent' && rand(0, 1)) {
                    $remarks = 'No notification received';
                } elseif ($status === 'late' && rand(0, 1)) {
                    $remarks = 'Transportation issue';
                }

                try {
                    Attendance::create([
                        'session_id' => $session->id,
                        'child_profile_id' => $childProfile->id,
                        'status' => $status,
                        'remarks' => $remarks,
                    ]);
                } catch (\Exception $e) {
                    // Log the error or handle it as needed
                    echo "Error creating attendance record: " . $e->getMessage() . "\n";
                }
            }
        }
    }
}
