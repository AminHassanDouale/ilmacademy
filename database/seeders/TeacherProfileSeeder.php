<?php

namespace Database\Seeders;

use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeacherProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get teacher users
        $teacherUsers = User::role('teacher')->get();

        // If there are no teacher users from UserRoleSeeder, create one
        if ($teacherUsers->isEmpty()) {
            $user = User::create([
                'name' => 'Teacher User',
                'email' => 'teacher@example.com',
                'password' => bcrypt('password'),
                'status' => 'active',
                'phone' => '123-456-7891',
                'email_verified_at' => now(),
            ]);

            $user->assignRole('teacher');
            $teacherUsers = collect([$user]);
        }

        $specializations = [
            'Mathematics', 'Physics', 'Chemistry', 'Biology',
            'History', 'Geography', 'English', 'French',
            'Computer Science', 'Physical Education'
        ];

        foreach ($teacherUsers as $user) {
            // If we don't have a specialization from the UserRoleSeeder, assign a random one
            $specialization = isset($user->teacherProfile) && $user->teacherProfile->specialization
                            ? $user->teacherProfile->specialization
                            : $specializations[array_rand($specializations)];

            TeacherProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'bio' => fake()->paragraph(),
                    'specialization' => $specialization,
                    'phone' => $user->phone ?? fake()->phoneNumber(),
                ]
            );
        }
    }
}
