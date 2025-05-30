<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\TeacherProfile;
use App\Models\ParentProfile;
use App\Models\ClientProfile;
use App\Models\ChildProfile;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $this->createPermissions();

        // Create roles
        $this->createRoles();

        // Create admin user
        $this->createAdminUser();

        // Create teachers
        $this->createTeachers();

        // Create parents and children
        $this->createParentsAndChildren();

        // Create students (ClientProfiles)
        $this->createStudents();
    }

    /**
     * Create permissions
     */
    private function createPermissions(): void
    {
        $permissions = [
            // Dashboard permissions
            'view dashboard',

            // User management permissions
            'view users', 'create users', 'edit users', 'delete users',

            // Role & Permission management
            'view roles', 'create roles', 'edit roles', 'delete roles',

            // Curriculum management
            'view curricula', 'create curricula', 'edit curricula', 'delete curricula',

            // Subject management
            'view subjects', 'create subjects', 'edit subjects', 'delete subjects',

            // Session management
            'view sessions', 'create sessions', 'edit sessions', 'delete sessions',

            // Attendance management
            'view attendance', 'take attendance', 'edit attendance',

            // Exam management
            'view exams', 'create exams', 'edit exams', 'delete exams', 'record exam results',

            // Enrollment management
            'view enrollments', 'create enrollments', 'edit enrollments', 'delete enrollments',

            // Financial management
            'view invoices', 'create invoices', 'edit invoices', 'delete invoices', 'process payments',

            // Report permissions
            'view reports', 'export reports'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }

    /**
     * Create roles and assign permissions
     */
    private function createRoles(): void
    {
        // Admin role - gets all permissions
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $adminRole->givePermissionTo(Permission::all());

        // Teacher role
        $teacherRole = Role::firstOrCreate(['name' => 'teacher']);
        $teacherRole->givePermissionTo([
            'view dashboard',
            'view sessions', 'create sessions', 'edit sessions',
            'view attendance', 'take attendance', 'edit attendance',
            'view exams', 'create exams', 'edit exams', 'record exam results',
            'view subjects',
            'view reports'
        ]);

        // Parent role
        $parentRole = Role::firstOrCreate(['name' => 'parent']);
        $parentRole->givePermissionTo([
            'view dashboard',
            'view enrollments', 'create enrollments',
            'view attendance',
            'view exams',
            'view invoices', 'process payments'
        ]);

        // Student role
        $studentRole = Role::firstOrCreate(['name' => 'student']);
        $studentRole->givePermissionTo([
            'view dashboard',
            'view enrollments',
            'view sessions',
            'view exams',
            'view invoices', 'process payments'
        ]);
    }

    /**
     * Create admin user
     */
    private function createAdminUser(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole('admin');
    }

    /**
     * Create teacher users
     */
    private function createTeachers(): void
    {
        // Math Teacher
        $mathTeacher = User::firstOrCreate(
            ['email' => 'john.smith@example.com'],
            [
                'name' => 'John Smith',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $mathTeacher->assignRole('teacher');

        TeacherProfile::firstOrCreate(
            ['user_id' => $mathTeacher->id],
            [
                'bio' => 'Mathematics teacher with 10 years of experience',
                'specialization' => 'Mathematics',
                'phone' => '123-456-7890',
            ]
        );

        // Science Teacher
        $scienceTeacher = User::firstOrCreate(
            ['email' => 'jane.doe@example.com'],
            [
                'name' => 'Jane Doe',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $scienceTeacher->assignRole('teacher');

        TeacherProfile::firstOrCreate(
            ['user_id' => $scienceTeacher->id],
            [
                'bio' => 'Science teacher specializing in Physics and Chemistry',
                'specialization' => 'Science',
                'phone' => '123-456-7891',
            ]
        );

        // English Teacher
        $englishTeacher = User::firstOrCreate(
            ['email' => 'robert.johnson@example.com'],
            [
                'name' => 'Robert Johnson',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $englishTeacher->assignRole('teacher');

        TeacherProfile::firstOrCreate(
            ['user_id' => $englishTeacher->id],
            [
                'bio' => 'English language and literature teacher',
                'specialization' => 'English',
                'phone' => '123-456-7892',
            ]
        );
    }

    /**
     * Create parent users with children
     */
    private function createParentsAndChildren(): void
    {
        // First parent with two children
        $parent1 = User::firstOrCreate(
            ['email' => 'alice.brown@example.com'],
            [
                'name' => 'Alice Brown',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $parent1->assignRole('parent');

        // Create child 1 for parent 1 (with user account)
        $child1User = User::firstOrCreate(
            ['email' => 'emma.brown@example.com'],
            [
                'name' => 'Emma Brown',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $child1User->assignRole('student');

        // Use the correct column name based on your database structure
        ChildProfile::firstOrCreate(
            [
                'parent_id' => $parent1->id, // Use parent user ID directly
                'user_id' => $child1User->id
            ],
            [
                'first_name' => 'Emma',
                'last_name' => 'Brown',
                'date_of_birth' => '2010-05-15',
                'gender' => 'female',
                'email' => 'emma.brown@example.com',
                'emergency_contact_name' => 'Alice Brown',
                'emergency_contact_phone' => '123-456-7893',
            ]
        );

        // Create child 2 for parent 1 (without user account - younger child)
        ChildProfile::firstOrCreate(
            [
                'parent_id' => $parent1->id, // Use parent user ID directly
                'first_name' => 'Liam',
                'last_name' => 'Brown'
            ],
            [
                'date_of_birth' => '2014-08-22',
                'gender' => 'male',
                'emergency_contact_name' => 'Alice Brown',
                'emergency_contact_phone' => '123-456-7893',
            ]
        );

        // Second parent with one child
        $parent2 = User::firstOrCreate(
            ['email' => 'michael.wilson@example.com'],
            [
                'name' => 'Michael Wilson',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $parent2->assignRole('parent');

        // Create child for parent 2 (with user account)
        $child3User = User::firstOrCreate(
            ['email' => 'james.wilson@example.com'],
            [
                'name' => 'James Wilson',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $child3User->assignRole('student');

        ChildProfile::firstOrCreate(
            [
                'parent_id' => $parent2->id, // Use parent user ID directly
                'user_id' => $child3User->id
            ],
            [
                'first_name' => 'James',
                'last_name' => 'Wilson',
                'date_of_birth' => '2009-11-10',
                'gender' => 'male',
                'email' => 'james.wilson@example.com',
                'emergency_contact_name' => 'Michael Wilson',
                'emergency_contact_phone' => '123-456-7894',
            ]
        );
    }

    /**
     * Create student users (adult learners with client profiles)
     */
    private function createStudents(): void
    {
        // Adult Student 1
        $student1 = User::firstOrCreate(
            ['email' => 'sarah.johnson@example.com'],
            [
                'name' => 'Sarah Johnson',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $student1->assignRole('student');

        ClientProfile::firstOrCreate(
            ['user_id' => $student1->id],
            [
                'phone' => '123-456-7895',
                'address' => '789 Pine Rd, Anytown, USA',
            ]
        );

        // Adult Student 2
        $student2 = User::firstOrCreate(
            ['email' => 'david.lee@example.com'],
            [
                'name' => 'David Lee',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $student2->assignRole('student');

        ClientProfile::firstOrCreate(
            ['user_id' => $student2->id],
            [
                'phone' => '123-456-7896',
                'address' => '101 Cedar Ln, Anytown, USA',
            ]
        );
    }
}
