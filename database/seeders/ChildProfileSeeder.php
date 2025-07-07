<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\ParentProfile;
use App\Models\ChildProfile;
use Carbon\Carbon;

class ChildProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all parent profiles
        $parentProfiles = ParentProfile::with('user')->get();

        if ($parentProfiles->isEmpty()) {
            $this->command->error('No parent profiles found. Please run ParentProfileSeeder first.');
            return;
        }

        $children = [
            // Children for each parent (using parent email to identify)
            'parent@example.com' => [
                [
                    'first_name' => 'Emma',
                    'last_name' => 'Johnson',
                    'date_of_birth' => Carbon::now()->subYears(8)->subMonths(3),
                    'gender' => 'female',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => 'Peanuts',
                    'special_needs' => null,
                    'notes' => 'Very active and loves reading',
                ],
                [
                    'first_name' => 'Liam',
                    'last_name' => 'Johnson',
                    'date_of_birth' => Carbon::now()->subYears(6)->subMonths(7),
                    'gender' => 'male',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => 'Asthma',
                    'allergies' => null,
                    'special_needs' => null,
                    'notes' => 'Needs inhaler during physical activities',
                ],
            ],
        ];

        // If we only have the default parent, add more children for variety
        if ($parentProfiles->count() === 1) {
            $defaultParentEmail = $parentProfiles->first()->user->email;
            $children[$defaultParentEmail] = [
                [
                    'first_name' => 'Sofia',
                    'last_name' => 'Rodriguez',
                    'date_of_birth' => Carbon::now()->subYears(9)->subMonths(1),
                    'gender' => 'female',
                    'email' => 'sofia.rodriguez@email.com',
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => null,
                    'special_needs' => null,
                    'notes' => 'Excellent at mathematics',
                ],
                [
                    'first_name' => 'Alex',
                    'last_name' => 'Chen',
                    'date_of_birth' => Carbon::now()->subYears(7)->subMonths(11),
                    'gender' => 'male',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => 'Dairy',
                    'special_needs' => null,
                    'notes' => 'Lactose intolerant - no milk products',
                ],
                [
                    'first_name' => 'Zoe',
                    'last_name' => 'Chen',
                    'date_of_birth' => Carbon::now()->subYears(5)->subMonths(4),
                    'gender' => 'female',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => null,
                    'special_needs' => 'Hearing impairment',
                    'notes' => 'Uses hearing aids',
                ],
                [
                    'first_name' => 'Mason',
                    'last_name' => 'Williams',
                    'date_of_birth' => Carbon::now()->subYears(10)->subMonths(2),
                    'gender' => 'male',
                    'email' => 'mason.williams@email.com',
                    'phone' => '+1-555-0403',
                    'medical_conditions' => null,
                    'allergies' => null,
                    'special_needs' => null,
                    'notes' => 'Captain of the school soccer team',
                ],
                [
                    'first_name' => 'Olivia',
                    'last_name' => 'Brown',
                    'date_of_birth' => Carbon::now()->subYears(8)->subMonths(9),
                    'gender' => 'female',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => 'Type 1 Diabetes',
                    'allergies' => null,
                    'special_needs' => null,
                    'notes' => 'Requires blood sugar monitoring and insulin',
                ],
                [
                    'first_name' => 'Noah',
                    'last_name' => 'Brown',
                    'date_of_birth' => Carbon::now()->subYears(6)->subMonths(1),
                    'gender' => 'male',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => 'Shellfish',
                    'special_needs' => null,
                    'notes' => 'Very creative, loves art class',
                ],
                [
                    'first_name' => 'Ava',
                    'last_name' => 'Miller',
                    'date_of_birth' => Carbon::now()->subYears(9)->subMonths(6),
                    'gender' => 'female',
                    'email' => 'ava.miller@email.com',
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => null,
                    'special_needs' => null,
                    'notes' => 'Bilingual (English/Spanish)',
                ],
                [
                    'first_name' => 'Ethan',
                    'last_name' => 'Davis',
                    'date_of_birth' => Carbon::now()->subYears(7)->subMonths(3),
                    'gender' => 'male',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => null,
                    'special_needs' => 'ADHD',
                    'notes' => 'Benefits from structured environment and clear instructions',
                ],
                [
                    'first_name' => 'Isabella',
                    'last_name' => 'Davis',
                    'date_of_birth' => Carbon::now()->subYears(5)->subMonths(8),
                    'gender' => 'female',
                    'email' => null,
                    'phone' => null,
                    'medical_conditions' => null,
                    'allergies' => null,
                    'special_needs' => null,
                    'notes' => 'Very social and outgoing',
                ],
            ];
        }

        $totalChildrenCreated = 0;

        foreach ($parentProfiles as $parentProfile) {
            $parentEmail = $parentProfile->user->email;
            $childrenForParent = $children[$parentEmail] ?? [];

            foreach ($childrenForParent as $childData) {
                ChildProfile::create([
                    'first_name' => $childData['first_name'],
                    'last_name' => $childData['last_name'],
                    'date_of_birth' => $childData['date_of_birth'],
                    'gender' => $childData['gender'],
                    'email' => $childData['email'],
                    'phone' => $childData['phone'],
                    'address' => $parentProfile->address, // Use parent's address
                    'emergency_contact_name' => $parentProfile->user->name,
                    'emergency_contact_phone' => $parentProfile->phone,

                    // Parent relationships
                    'parent_id' => $parentProfile->user_id, // User ID of parent
                    'parent_profile_id' => $parentProfile->id, // Direct ParentProfile relationship

                    'user_id' => null, // Children might not have user accounts initially
                    'medical_conditions' => $childData['medical_conditions'],
                    'allergies' => $childData['allergies'],
                    'special_needs' => $childData['special_needs'],
                    'additional_needs' => null,
                    'notes' => $childData['notes'],
                    'medical_information' => $childData['medical_conditions'], // Legacy field
                ]);

                $totalChildrenCreated++;
            }
        }

        $this->command->info("ChildProfile seeder completed successfully!");
        $this->command->info("Created {$totalChildrenCreated} child profiles for {$parentProfiles->count()} parents");

        // Show the relationships created
        foreach ($parentProfiles as $parentProfile) {
            $childCount = $parentProfile->children()->count();
            $this->command->info("- {$parentProfile->user->name}: {$childCount} children");
        }
    }
}
