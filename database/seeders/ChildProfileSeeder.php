<?php

namespace Database\Seeders;

use App\Models\ChildProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChildProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users with parent role
        $parentUsers = User::whereHas('roles', function($q) {
            $q->where('name', 'parent');
        })->get();

        // Get student users that don't have child profiles yet
        $studentUsers = User::whereHas('roles', function($q) {
            $q->where('name', 'student');
        })
        ->whereDoesntHave('childProfile') // Now this should work
        ->get()
        ->keyBy('id');

        $unusedStudentUsers = $studentUsers->toArray();

        if ($parentUsers->isEmpty()) {
            $this->command->info('No parent users found. Creating some children without parent assignments...');

            // Create some standalone child profiles for testing
            for ($i = 0; $i < 10; $i++) {
                $userId = null;

                // Assign a student user if available
                if (!empty($unusedStudentUsers)) {
                    $keys = array_keys($unusedStudentUsers);
                    $randomKey = $keys[array_rand($keys)];
                    $userId = $randomKey;
                    unset($unusedStudentUsers[$randomKey]);
                }

                ChildProfile::create([
                    'first_name' => fake()->firstName(),
                    'last_name' => fake()->lastName(),
                    'parent_id' => null,
                    'user_id' => $userId,
                    'date_of_birth' => fake()->dateTimeBetween('-18 years', '-5 years'),
                    'gender' => fake()->randomElement(['male', 'female', 'other']),
                    'email' => $userId ? null : fake()->unique()->safeEmail(),
                    'phone' => fake()->phoneNumber(),
                    'address' => fake()->address(),
                    'emergency_contact_name' => fake()->name(),
                    'emergency_contact_phone' => fake()->phoneNumber(),
                    'medical_conditions' => rand(0, 10) > 8 ? fake()->paragraph() : null,
                    'allergies' => rand(0, 10) > 8 ? fake()->sentence() : null,
                    'notes' => rand(0, 10) > 7 ? fake()->paragraph() : null,
                ]);
            }
            return;
        }

        foreach ($parentUsers as $parentUser) {
            // Skip if this parent already has children
            if ($parentUser->children()->exists()) {
                continue;
            }

            // Each parent has 1-3 children
            $numChildren = rand(1, 3);

            for ($i = 0; $i < $numChildren; $i++) {
                $userId = null;

                // If we have unused student users, assign one randomly to the child
                if (!empty($unusedStudentUsers)) {
                    $keys = array_keys($unusedStudentUsers);
                    $randomKey = $keys[array_rand($keys)];
                    $userId = $randomKey;
                    unset($unusedStudentUsers[$randomKey]);
                }

                ChildProfile::create([
                    'first_name' => fake()->firstName(),
                    'last_name' => fake()->lastName(),
                    'parent_id' => $parentUser->id,
                    'user_id' => $userId,
                    'date_of_birth' => fake()->dateTimeBetween('-18 years', '-5 years'),
                    'gender' => fake()->randomElement(['male', 'female', 'other']),
                    'email' => $userId ? null : fake()->unique()->safeEmail(),
                    'phone' => fake()->phoneNumber(),
                    'address' => fake()->address(),
                    'emergency_contact_name' => fake()->name(),
                    'emergency_contact_phone' => fake()->phoneNumber(),
                    'medical_conditions' => rand(0, 10) > 8 ? fake()->paragraph() : null,
                    'allergies' => rand(0, 10) > 8 ? fake()->sentence() : null,
                    'notes' => rand(0, 10) > 7 ? fake()->paragraph() : null,
                ]);
            }
        }

        $this->command->info('Created child profiles successfully!');
    }
}
