<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\ClientProfile;
use Illuminate\Support\Facades\Hash;

class ClientProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = [
            [
                'name' => 'Sarah Johnson',
                'email' => 'sarah.johnson@email.com',
                'phone' => '+1-555-0101',
                'address' => '123 Maple Street, Springfield, IL 62701',
                'occupation' => 'Software Engineer',
                'company' => 'Tech Solutions Inc.',
                'relationship_to_children' => 'Mother',
                'preferred_contact_method' => 'email',
                'emergency_contact_name' => 'Michael Johnson',
                'emergency_contact_phone' => '+1-555-0102',
            ],
            [
                'name' => 'Michael Rodriguez',
                'email' => 'michael.rodriguez@email.com',
                'phone' => '+1-555-0201',
                'address' => '456 Oak Avenue, Chicago, IL 60601',
                'occupation' => 'Doctor',
                'company' => 'Chicago Medical Center',
                'relationship_to_children' => 'Father',
                'preferred_contact_method' => 'phone',
                'emergency_contact_name' => 'Maria Rodriguez',
                'emergency_contact_phone' => '+1-555-0202',
            ],
            [
                'name' => 'Emily Chen',
                'email' => 'emily.chen@email.com',
                'phone' => '+1-555-0301',
                'address' => '789 Pine Road, Boston, MA 02101',
                'occupation' => 'Teacher',
                'company' => 'Boston Elementary School',
                'relationship_to_children' => 'Mother',
                'preferred_contact_method' => 'sms',
                'emergency_contact_name' => 'David Chen',
                'emergency_contact_phone' => '+1-555-0302',
            ],
            [
                'name' => 'Robert Williams',
                'email' => 'robert.williams@email.com',
                'phone' => '+1-555-0401',
                'address' => '321 Cedar Lane, Houston, TX 77001',
                'occupation' => 'Engineer',
                'company' => 'Energy Corp',
                'relationship_to_children' => 'Father',
                'preferred_contact_method' => 'email',
                'emergency_contact_name' => 'Linda Williams',
                'emergency_contact_phone' => '+1-555-0402',
            ],
            [
                'name' => 'Jessica Brown',
                'email' => 'jessica.brown@email.com',
                'phone' => '+1-555-0501',
                'address' => '654 Birch Street, Phoenix, AZ 85001',
                'occupation' => 'Nurse',
                'company' => 'Phoenix General Hospital',
                'relationship_to_children' => 'Mother',
                'preferred_contact_method' => 'whatsapp',
                'emergency_contact_name' => 'James Brown',
                'emergency_contact_phone' => '+1-555-0502',
            ],
            [
                'name' => 'David Miller',
                'email' => 'david.miller@email.com',
                'phone' => '+1-555-0601',
                'address' => '987 Elm Drive, Miami, FL 33101',
                'occupation' => 'Business Owner',
                'company' => 'Miller Consulting',
                'relationship_to_children' => 'Father',
                'preferred_contact_method' => 'phone',
                'emergency_contact_name' => 'Amanda Miller',
                'emergency_contact_phone' => '+1-555-0602',
            ],
            [
                'name' => 'Lisa Davis',
                'email' => 'lisa.davis@email.com',
                'phone' => '+1-555-0701',
                'address' => '147 Willow Way, Denver, CO 80201',
                'occupation' => 'Lawyer',
                'company' => 'Davis & Associates',
                'relationship_to_children' => 'Mother',
                'preferred_contact_method' => 'email',
                'emergency_contact_name' => 'Paul Davis',
                'emergency_contact_phone' => '+1-555-0702',
            ],
            [
                'name' => 'Christopher Wilson',
                'email' => 'christopher.wilson@email.com',
                'phone' => '+1-555-0801',
                'address' => '258 Spruce Court, Seattle, WA 98101',
                'occupation' => 'Architect',
                'company' => 'Wilson Design Studio',
                'relationship_to_children' => 'Father',
                'preferred_contact_method' => 'sms',
                'emergency_contact_name' => 'Jennifer Wilson',
                'emergency_contact_phone' => '+1-555-0802',
            ],
            [
                'name' => 'Amanda Garcia',
                'email' => 'amanda.garcia@email.com',
                'phone' => '+1-555-0901',
                'address' => '369 Poplar Place, Portland, OR 97201',
                'occupation' => 'Marketing Manager',
                'company' => 'Digital Marketing Solutions',
                'relationship_to_children' => 'Mother',
                'preferred_contact_method' => 'email',
                'emergency_contact_name' => 'Carlos Garcia',
                'emergency_contact_phone' => '+1-555-0902',
            ],
            [
                'name' => 'Thomas Anderson',
                'email' => 'thomas.anderson@email.com',
                'phone' => '+1-555-1001',
                'address' => '741 Ash Boulevard, Atlanta, GA 30301',
                'occupation' => 'Financial Advisor',
                'company' => 'Anderson Financial',
                'relationship_to_children' => 'Father',
                'preferred_contact_method' => 'phone',
                'emergency_contact_name' => 'Rachel Anderson',
                'emergency_contact_phone' => '+1-555-1002',
            ],
        ];

        foreach ($clients as $clientData) {
            // Create User first
            $user = User::create([
                'name' => $clientData['name'],
                'email' => $clientData['email'],
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
            ]);

            // Create ClientProfile
            ClientProfile::create([
                'user_id' => $user->id,
                'phone' => $clientData['phone'],
                'address' => $clientData['address'],
                'occupation' => $clientData['occupation'],
                'company' => $clientData['company'],
                'relationship_to_children' => $clientData['relationship_to_children'],
                'preferred_contact_method' => $clientData['preferred_contact_method'],
                'emergency_contact_name' => $clientData['emergency_contact_name'],
                'emergency_contact_phone' => $clientData['emergency_contact_phone'],
                'allow_marketing_emails' => fake()->boolean(70), // 70% chance of true
                'allow_sms_notifications' => fake()->boolean(80), // 80% chance of true
                'notes' => fake()->optional(0.3)->sentence(), // 30% chance of having notes
            ]);
        }

        $this->command->info('ClientProfile seeder completed successfully!');
    }
}
