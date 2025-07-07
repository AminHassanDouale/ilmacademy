<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check what columns exist in the notifications table
        $columns = Schema::getColumnListing('notifications');

        $this->command->info('Detected notifications table columns: ' . implode(', ', $columns));

        // Determine which structure we're working with
        $hasUserIdColumn = in_array('user_id', $columns);
        $hasDataColumn = in_array('data', $columns);
        $hasNotifiableColumns = in_array('notifiable_type', $columns) && in_array('notifiable_id', $columns);

        // Get users to send notifications to
        $users = User::take(5)->get();

        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please create users first.');
            return;
        }

        if ($hasDataColumn && $hasNotifiableColumns) {
            // Laravel's default notification structure
            $this->command->info('Using Laravel default notification structure');
            $this->createLaravelNotifications($users);
        } elseif ($hasUserIdColumn) {
            // Custom notification structure
            $this->command->info('Using custom notification structure');
            $this->createCustomNotifications($users);
        } else {
            $this->command->error('Unknown notification table structure. Please check your migration.');
            return;
        }

        $this->command->info('Sample notifications created successfully!');
    }

    private function createLaravelNotifications($users): void
    {
        $now = Carbon::now();

        foreach ($users as $user) {
            $notifications = [
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'App\Notifications\WelcomeNotification',
                    'notifiable_type' => 'App\Models\User',
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'title' => 'Welcome to our platform!',
                        'message' => "Hello {$user->name}! Welcome to our platform.",
                        'user_name' => $user->name,
                    ]),
                    'read_at' => null,
                    'created_at' => $now->copy()->subDays(1),
                    'updated_at' => $now->copy()->subDays(1),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'App\Notifications\PaymentReceivedNotification',
                    'notifiable_type' => 'App\Models\User',
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'title' => 'Payment Received',
                        'message' => 'Your payment of $250.00 has been processed.',
                        'amount' => 250.00,
                    ]),
                    'read_at' => $now->copy()->subHours(6),
                    'created_at' => $now->copy()->subDays(3),
                    'updated_at' => $now->copy()->subHours(6),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'type' => 'App\Notifications\AssignmentDueNotification',
                    'notifiable_type' => 'App\Models\User',
                    'notifiable_id' => $user->id,
                    'data' => json_encode([
                        'title' => 'Assignment Due: Final Project',
                        'message' => 'Your assignment is due soon.',
                        'due_date' => $now->copy()->addDays(5)->format('M d, Y'),
                    ]),
                    'read_at' => null,
                    'created_at' => $now->copy()->subHours(3),
                    'updated_at' => $now->copy()->subHours(3),
                ],
            ];

            DB::table('notifications')->insert($notifications);
        }
    }

    private function createCustomNotifications($users): void
    {
        $now = Carbon::now();

        foreach ($users as $user) {
            $notifications = [
                [
                    'user_id' => $user->id,
                    'type' => 'welcome',
                    'title' => 'Welcome to our platform!',
                    'message' => "Hello {$user->name}! Welcome to our platform. We're excited to have you on board.",
                    'read_at' => null,
                    'created_at' => $now->copy()->subDays(1),
                    'updated_at' => $now->copy()->subDays(1),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'event_reminder',
                    'title' => 'Event Reminder: Annual Conference',
                    'message' => "Don't forget about the upcoming event on " . $now->copy()->addDays(3)->format('M d, Y') . " at Main Auditorium.",
                    'read_at' => $now->copy()->subHours(2),
                    'created_at' => $now->copy()->subDays(2),
                    'updated_at' => $now->copy()->subHours(2),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'payment_received',
                    'title' => 'Payment Received',
                    'message' => 'Your payment of $250.00 has been successfully processed. Reference: PAY-' . strtoupper(Str::random(8)),
                    'read_at' => $now->copy()->subHours(6),
                    'created_at' => $now->copy()->subDays(3),
                    'updated_at' => $now->copy()->subHours(6),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'assignment_due',
                    'title' => 'Assignment Due: Final Project',
                    'message' => 'Your assignment for Computer Science 101 is due on ' . $now->copy()->addDays(5)->format('M d, Y') . '. Please submit before the deadline.',
                    'read_at' => null,
                    'created_at' => $now->copy()->subHours(3),
                    'updated_at' => $now->copy()->subHours(3),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'system_maintenance',
                    'title' => 'Scheduled System Maintenance',
                    'message' => 'System maintenance scheduled for ' . $now->copy()->addDays(7)->format('M d, Y') . ' from 2:00 AM to 4:00 AM.',
                    'read_at' => null,
                    'created_at' => $now->copy()->subHours(1),
                    'updated_at' => $now->copy()->subHours(1),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'new_message',
                    'title' => 'New Message from Admin',
                    'message' => 'Subject: Welcome and Important Updates. Welcome to the new semester! We have some important updates to share.',
                    'read_at' => null,
                    'created_at' => $now->copy()->subMinutes(30),
                    'updated_at' => $now->copy()->subMinutes(30),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'grade_posted',
                    'title' => 'New Grade Posted',
                    'message' => 'Your grade for Mathematics Quiz 3 has been posted. Score: 95/100. Great job!',
                    'read_at' => null,
                    'created_at' => $now->copy()->subMinutes(15),
                    'updated_at' => $now->copy()->subMinutes(15),
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'fee_reminder',
                    'title' => 'Fee Payment Reminder',
                    'message' => 'Your tuition fee payment of $500.00 is due on ' . $now->copy()->addDays(10)->format('M d, Y') . '.',
                    'read_at' => null,
                    'created_at' => $now->copy()->subHours(4),
                    'updated_at' => $now->copy()->subHours(4),
                ],
            ];

            DB::table('notifications')->insert($notifications);
        }
    }
}
