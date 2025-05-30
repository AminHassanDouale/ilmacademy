<?php

namespace Database\Seeders;

use App\Models\ParentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class ParentProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
                'phone' => $user->phone ?? '123-456-7890',
                'address' => '123 Main Street, City, Country',
                'emergency_contact' => '987-654-3210',
                'occupation' => 'Professional',
                'company' => 'ABC Company',
                'notes' => 'Sample parent profile',
            ]);
        }
    }
}
