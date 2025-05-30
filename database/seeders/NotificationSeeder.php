<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        $notificationTypes = [
            'payment_due' => 'Payment Due Reminder',
            'payment_received' => 'Payment Received',
            'exam_scheduled' => 'Exam Scheduled',
            'exam_result' => 'Exam Results Available',
            'system' => 'System Notification',
            'enrollment' => 'Enrollment Update',
            'attendance' => 'Attendance Alert',
        ];

        foreach ($users as $user) {
            // Generate between 1 and 10 notifications per user
            $numNotifications = rand(1, 10);

            for ($i = 0; $i < $numNotifications; $i++) {
                $type = array_rand($notificationTypes);
                $title = $notificationTypes[$type];

                // Generate message based on notification type
                $message = match($type) {
                    'payment_due' => 'You have an upcoming payment due on ' . fake()->date(),
                    'payment_received' => 'Your payment of ' . fake()->randomFloat(2, 100, 5000) . ' has been received',
                    'exam_scheduled' => 'An exam has been scheduled for ' . fake()->date(),
                    'exam_result' => 'New exam results are available for viewing',
                    'system' => 'System maintenance scheduled for ' . fake()->date(),
                    'enrollment' => 'Your enrollment status has been updated',
                    'attendance' => 'Attendance alert: ' . fake()->randomElement(['Absence reported', 'Late arrival', 'Early departure']),
                    default => 'You have a new notification',
                };

                // Some notifications are read, some are unread
                $readAt = fake()->boolean(70) ? fake()->dateTimeBetween('-30 days', 'now') : null;

                Notification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'read_at' => $readAt,
                ]);
            }
        }
    }
}
