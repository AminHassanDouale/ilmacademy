<?php

namespace Database\Seeders;

use App\Models\ParentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class ParentProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Get parent users
        $parentUsers = User::role('parent')->get();

        // If there are no parent users from UserRoleSeeder, create one
        if ($parentUsers->isEmpty()) {
            $user = User::create([
                'name' => 'Parent User',
                'email' => 'parent@example.com',
                'password' => bcrypt('password'),
                'status' => 'active',
                'phone' => '123-456-7890',
                'email_verified_at' => now(),
            ]);

            $user->assignRole('parent');
            $parentUsers = collect([$user]);
        }

        foreach ($parentUsers as $user) {
            ParentProfile::create([
                'user_id' => $user->id,
                'phone' => $user->phone ?? $faker->phoneNumber(),
                'address' => $faker->address(),
                'emergency_contact' => $faker->phoneNumber(),
                'occupation' => $faker->jobTitle(),
                'company' => $faker->company(),
                'notes' => $faker->sentence(),
            ]);
        }
    }
}
