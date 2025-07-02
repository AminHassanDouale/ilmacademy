<?php

namespace Database\Seeders;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Notifications\EventReminderNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\AssignmentDueNotification;
use App\Notifications\SystemMaintenanceNotification;
use App\Notifications\NewMessageNotification;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users to send notifications to
        $users = User::take(5)->get();

        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please create users first.');
            return;
        }

        foreach ($users as $user) {
            // Send different types of notifications
            $this->createSampleNotifications($user);
        }

        $this->command->info('Sample notifications created successfully!');
    }

    private function createSampleNotifications(User $user): void
    {
        $now = Carbon::now();

        // Create notifications directly in database for variety
        $notifications = [
            // Welcome notification (unread)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => WelcomeNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Welcome to our platform!',
                    'message' => "Hello {$user->name}! Welcome to our platform. We're excited to have you on board.",
                    'action_text' => 'Get Started',
                    'action_url' => route('dashboard'),
                    'user_name' => $user->name,
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subDays(1),
                'updated_at' => $now->copy()->subDays(1),
            ],

            // Event reminder (read)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => EventReminderNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Event Reminder: Annual Conference',
                    'message' => "Don't forget about the upcoming event on " . $now->copy()->addDays(3)->format('M d, Y') . " at Main Auditorium.",
                    'action_text' => 'View Event',
                    'action_url' => route('admin.calendar.index'),
                    'event_title' => 'Annual Conference',
                    'event_date' => $now->copy()->addDays(3)->format('M d, Y'),
                    'event_location' => 'Main Auditorium',
                ]),
                'read_at' => $now->copy()->subHours(2),
                'created_at' => $now->copy()->subDays(2),
                'updated_at' => $now->copy()->subHours(2),
            ],

            // Payment received (read)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => PaymentReceivedNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Payment Received',
                    'message' => 'Your payment of $250.00 has been successfully processed.',
                    'action_text' => 'View Payment',
                    'action_url' => '#',
                    'amount' => 250.00,
                    'payment_method' => 'Credit Card',
                    'reference' => 'PAY-' . strtoupper(\Illuminate\Support\Str::random(8)),
                ]),
                'read_at' => $now->copy()->subHours(6),
                'created_at' => $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subHours(6),
            ],

            // Assignment due (unread)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => AssignmentDueNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Assignment Due: Final Project',
                    'message' => 'Your assignment for Computer Science 101 is due on ' . $now->copy()->addDays(5)->format('M d, Y') . '.',
                    'action_text' => 'View Assignment',
                    'action_url' => '#',
                    'assignment_title' => 'Final Project',
                    'due_date' => $now->copy()->addDays(5)->format('M d, Y'),
                    'course_name' => 'Computer Science 101',
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subHours(3),
                'updated_at' => $now->copy()->subHours(3),
            ],

            // System maintenance (unread)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => SystemMaintenanceNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Scheduled System Maintenance',
                    'message' => 'System maintenance scheduled for ' . $now->copy()->addDays(7)->format('M d, Y') . ' from 2:00 AM to 4:00 AM. Database optimization and security updates.',
                    'maintenance_date' => $now->copy()->addDays(7)->format('M d, Y'),
                    'start_time' => '2:00 AM',
                    'end_time' => '4:00 AM',
                    'description' => 'Database optimization and security updates',
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subHours(1),
                'updated_at' => $now->copy()->subHours(1),
            ],

            // New message (unread)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => NewMessageNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'New Message from Admin',
                    'message' => 'Subject: Welcome and Important Updates. Welcome to the new semester! We have some important updates to share...',
                    'action_text' => 'Read Message',
                    'action_url' => '#',
                    'sender_name' => 'Admin',
                    'subject' => 'Welcome and Important Updates',
                    'preview' => 'Welcome to the new semester! We have some important updates to share...',
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subMinutes(30),
                'updated_at' => $now->copy()->subMinutes(30),
            ],

            // Another event reminder (read, older)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => EventReminderNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Event Reminder: Parent-Teacher Meeting',
                    'message' => "Don't forget about the upcoming event on " . $now->copy()->subDays(1)->format('M d, Y') . " at Conference Room A.",
                    'action_text' => 'View Event',
                    'action_url' => route('admin.calendar.index'),
                    'event_title' => 'Parent-Teacher Meeting',
                    'event_date' => $now->copy()->subDays(1)->format('M d, Y'),
                    'event_location' => 'Conference Room A',
                ]),
                'read_at' => $now->copy()->subDays(1)->subHours(2),
                'created_at' => $now->copy()->subDays(5),
                'updated_at' => $now->copy()->subDays(1)->subHours(2),
            ],

            // User activity notification (read)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'App\Notifications\UserActivityNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'Profile Updated Successfully',
                    'message' => 'Your profile information has been updated successfully.',
                    'activity_type' => 'profile_update',
                ]),
                'read_at' => $now->copy()->subDays(2),
                'created_at' => $now->copy()->subDays(4),
                'updated_at' => $now->copy()->subDays(2),
            ],

            // Grade notification (unread)
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'App\Notifications\GradeNotification',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => 'New Grade Posted',
                    'message' => 'Your grade for Mathematics Quiz 3 has been posted. Score: 95/100',
                    'action_text' => 'View Grades',
                    'action_url' => '#',
                    'course' => 'Mathematics',
                    'assignment' => 'Quiz 3',
                    'score' => '95/100',
                ]),
                'read_at' => null,
                'created_at' => $now->copy()->subMinutes(15),
                'updated_at' => $now->copy()->subMinutes(15),
            ],
        ];

        // Insert notifications into database
        DB::table('notifications')->insert($notifications);
    }
}
