<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\TeacherProfile;
use Illuminate\Support\Facades\Hash;

class TeacherProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $teachers = [
            [
                'name' => 'Dr. Sarah Johnson',
                'email' => 'sarah.johnson@school.edu',
                'specialization' => 'Mathematics',
                'department' => 'Mathematics',
                'qualification' => 'PhD in Mathematics',
                'experience_years' => 12,
                'employee_id' => 'TCH001',
                'phone' => '+1-555-1001',
                'bio' => 'Experienced mathematics teacher with expertise in calculus and algebra.',
            ],
            [
                'name' => 'Prof. Michael Chen',
                'email' => 'michael.chen@school.edu',
                'specialization' => 'Physics',
                'department' => 'Science',
                'qualification' => 'MSc in Physics',
                'experience_years' => 8,
                'employee_id' => 'TCH002',
                'phone' => '+1-555-1002',
                'bio' => 'Physics teacher specializing in quantum mechanics and thermodynamics.',
            ],
            [
                'name' => 'Ms. Emily Rodriguez',
                'email' => 'emily.rodriguez@school.edu',
                'specialization' => 'English Literature',
                'department' => 'Languages',
                'qualification' => 'MA in English Literature',
                'experience_years' => 6,
                'employee_id' => 'TCH003',
                'phone' => '+1-555-1003',
                'bio' => 'English literature teacher with passion for creative writing and poetry.',
            ],
            [
                'name' => 'Dr. Robert Williams',
                'email' => 'robert.williams@school.edu',
                'specialization' => 'Chemistry',
                'department' => 'Science',
                'qualification' => 'PhD in Chemistry',
                'experience_years' => 15,
                'employee_id' => 'TCH004',
                'phone' => '+1-555-1004',
                'bio' => 'Chemistry teacher and researcher with expertise in organic chemistry.',
            ],
            [
                'name' => 'Ms. Jessica Brown',
                'email' => 'jessica.brown@school.edu',
                'specialization' => 'History',
                'department' => 'Social Studies',
                'qualification' => 'MA in History',
                'experience_years' => 9,
                'employee_id' => 'TCH005',
                'phone' => '+1-555-1005',
                'bio' => 'History teacher specializing in modern world history and civics.',
            ],
            [
                'name' => 'Mr. David Miller',
                'email' => 'david.miller@school.edu',
                'specialization' => 'Computer Science',
                'department' => 'Technology',
                'qualification' => 'MSc in Computer Science',
                'experience_years' => 7,
                'employee_id' => 'TCH006',
                'phone' => '+1-555-1006',
                'bio' => 'Computer science teacher with expertise in programming and web development.',
            ],
            [
                'name' => 'Ms. Lisa Davis',
                'email' => 'lisa.davis@school.edu',
                'specialization' => 'Biology',
                'department' => 'Science',
                'qualification' => 'MSc in Biology',
                'experience_years' => 10,
                'employee_id' => 'TCH007',
                'phone' => '+1-555-1007',
                'bio' => 'Biology teacher with focus on ecology and environmental science.',
            ],
            [
                'name' => 'Mr. Christopher Wilson',
                'email' => 'christopher.wilson@school.edu',
                'specialization' => 'Physical Education',
                'department' => 'Physical Education',
                'qualification' => 'BA in Sports Science',
                'experience_years' => 5,
                'employee_id' => 'TCH008',
                'phone' => '+1-555-1008',
                'bio' => 'Physical education teacher and sports coach.',
            ],
            [
                'name' => 'Ms. Amanda Garcia',
                'email' => 'amanda.garcia@school.edu',
                'specialization' => 'Art',
                'department' => 'Arts',
                'qualification' => 'BFA in Fine Arts',
                'experience_years' => 4,
                'employee_id' => 'TCH009',
                'phone' => '+1-555-1009',
                'bio' => 'Art teacher specializing in painting and sculpture.',
            ],
            [
                'name' => 'Mr. Thomas Anderson',
                'email' => 'thomas.anderson@school.edu',
                'specialization' => 'Music',
                'department' => 'Arts',
                'qualification' => 'BMus in Music Education',
                'experience_years' => 11,
                'employee_id' => 'TCH010',
                'phone' => '+1-555-1010',
                'bio' => 'Music teacher and orchestra conductor.',
            ],
        ];

        foreach ($teachers as $teacherData) {
            // Create User first
            $user = User::create([
                'name' => $teacherData['name'],
                'email' => $teacherData['email'],
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]);

            // Assign teacher role if using Spatie Permission
            if (method_exists($user, 'assignRole')) {
                $user->assignRole('teacher');
            }

            // Create TeacherProfile
            TeacherProfile::create([
                'user_id' => $user->id,
                'bio' => $teacherData['bio'],
                'specialization' => $teacherData['specialization'],
                'phone' => $teacherData['phone'],
                'employee_id' => $teacherData['employee_id'],
                'department' => $teacherData['department'],
                'qualification' => $teacherData['qualification'],
                'experience_years' => $teacherData['experience_years'],
                'date_joined' => now()->subMonths(rand(1, 60)), // Random join date within last 5 years
                'status' => 'active',
            ]);
        }

        $this->command->info('TeacherProfile seeder completed successfully!');
        $this->command->info('Created ' . count($teachers) . ' teacher profiles');
    }
}
