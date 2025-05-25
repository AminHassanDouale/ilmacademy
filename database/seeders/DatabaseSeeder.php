<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Core system and users
            UserRoleSeeder::class,
            AcademicYearSeeder::class,

            // Profiles
           // ParentProfileSeeder::class,
           // TeacherProfileSeeder::class,
           // ChildProfileSeeder::class,
           // ClientProfileSeeder::class,

            // Academic structure
            CurriculumSeeder::class,
            SubjectSeeder::class,
            TeacherSubjectSeeder::class,
            RoomSeeder::class,

            // Enrollments
            PaymentPlanSeeder::class,
            ProgramEnrollmentSeeder::class,
            SubjectEnrollmentSeeder::class,

            // Schedule
            TimetableSlotSeeder::class,
            SessionSeeder::class,
            AttendanceSeeder::class,

            // Assessments
            ExamSeeder::class,
            ExamResultSeeder::class,

            // Financial
            InvoiceSeeder::class,
            InvoiceItemSeeder::class,
            PaymentSeeder::class,

            // System
            NotificationSeeder::class,
            ActivityLogSeeder::class,


        ]);
    }
}
