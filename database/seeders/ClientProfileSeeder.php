<?php

namespace Database\Seeders;

use App\Models\ClientProfile;
use App\Models\User;
use App\Models\ChildProfile;
use Illuminate\Database\Seeder;

class ClientProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Find users with student role (not client role)
        $studentUsers = User::role('student')->get();

        // Filter out students who already have a ChildProfile
        $clientUsers = $studentUsers->filter(function($user) {
            return !ChildProfile::where('user_id', $user->id)->exists();
        });

        // If there are no appropriate users, create one
        if ($clientUsers->isEmpty()) {
            $user = User::create([
                'name' => 'Adult Student',
                'email' => 'adult.student@example.com',
                'password' => bcrypt('password'),
                'status' => 'active', // Only include this field if it exists in your users table
                'email_verified_at' => now(),
                // Removed the 'phone' field since it doesn't exist in the users table
            ]);

            $user->assignRole('student'); // Use 'student' role from UserRoleSeeder
            $clientUsers = collect([$user]);
        }

        foreach ($clientUsers as $user) {
            // Check if the user already has a client profile
            if (!ClientProfile::where('user_id', $user->id)->exists()) {
                ClientProfile::create([
                    'user_id' => $user->id,
                    'phone' => fake()->phoneNumber(), // Phone goes in ClientProfile, not User
                    'address' => fake()->address(),
                ]);
            }
        }
    }
}
