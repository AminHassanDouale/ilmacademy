<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Notifications\EventReminderNotification;
use App\Notifications\PaymentReceivedNotification;
use App\Notifications\AssignmentDueNotification;
use App\Notifications\SystemMaintenanceNotification;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class NotificationService
{
    /**
     * Send a welcome notification to a user
     */
    public function sendWelcomeNotification(User $user, ?string $actionUrl = null): bool
    {
        try {
            $user->notify(new WelcomeNotification(
                userName: $user->name,
                actionUrl: $actionUrl
            ));

            Log::info('Welcome notification sent', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send welcome notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send event reminder notification
     */
    public function sendEventReminder(
        User|Collection $users,
        string $eventTitle,
        string $eventDate,
        ?string $eventLocation = null,
        ?string $actionUrl = null
    ): bool {
        try {
            $notification = new EventReminderNotification(
                eventTitle: $eventTitle,
                eventDate: $eventDate,
                eventLocation: $eventLocation,
                actionUrl: $actionUrl
            );

            if ($users instanceof User) {
                $users->notify($notification);
                $userCount = 1;
            } else {
                Notification::send($users, $notification);
                $userCount = $users->count();
            }

            Log::info('Event reminder notifications sent', [
                'event_title' => $eventTitle,
                'user_count' => $userCount
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send event reminder notifications', [
                'event_title' => $eventTitle,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send payment received notification
     */
    public function sendPaymentReceived(
        User $user,
        float $amount,
        string $paymentMethod,
        string $reference,
        ?string $actionUrl = null
    ): bool {
        try {
            $user->notify(new PaymentReceivedNotification(
                amount: $amount,
                paymentMethod: $paymentMethod,
                reference: $reference,
                actionUrl: $actionUrl
            ));

            Log::info('Payment received notification sent', [
                'user_id' => $user->id,
                'amount' => $amount,
                'reference' => $reference
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send payment received notification', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send assignment due notification
     */
    public function sendAssignmentDue(
        User|Collection $users,
        string $assignmentTitle,
        string $dueDate,
        string $courseName,
        ?string $actionUrl = null
    ): bool {
        try {
            $notification = new AssignmentDueNotification(
                assignmentTitle: $assignmentTitle,
                dueDate: $dueDate,
                courseName: $courseName,
                actionUrl: $actionUrl
            );

            if ($users instanceof User) {
                $users->notify($notification);
                $userCount = 1;
            } else {
                Notification::send($users, $notification);
                $userCount = $users->count();
            }

            Log::info('Assignment due notifications sent', [
                'assignment_title' => $assignmentTitle,
                'course_name' => $courseName,
                'user_count' => $userCount
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send assignment due notifications', [
                'assignment_title' => $assignmentTitle,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send system maintenance notification to all users
     */
    public function sendSystemMaintenance(
        string $maintenanceDate,
        string $startTime,
        string $endTime,
        string $description,
        ?Collection $users = null
    ): bool {
        try {
            $notification = new SystemMaintenanceNotification(
                maintenanceDate: $maintenanceDate,
                startTime: $startTime,
                endTime: $endTime,
                description: $description
            );

            $targetUsers = $users ?? User::all();
            Notification::send($targetUsers, $notification);

            Log::info('System maintenance notifications sent', [
                'maintenance_date' => $maintenanceDate,
                'user_count' => $targetUsers->count()
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send system maintenance notifications', [
                'maintenance_date' => $maintenanceDate,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send new message notification
     */
    public function sendNewMessage(
        User $user,
        string $senderName,
        string $subject,
        string $preview,
        ?string $actionUrl = null
    ): bool {
        try {
            $user->notify(new NewMessageNotification(
                senderName: $senderName,
                subject: $subject,
                preview: $preview,
                actionUrl: $actionUrl
            ));

            Log::info('New message notification sent', [
                'user_id' => $user->id,
                'sender_name' => $senderName,
                'subject' => $subject
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send new message notification', [
                'user_id' => $user->id,
                'sender_name' => $senderName,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send bulk notifications to multiple users
     */
    public function sendBulkNotification(
        Collection $users,
        string $notificationClass,
        array $data
    ): bool {
        try {
            if (!class_exists($notificationClass)) {
                throw new \InvalidArgumentException("Notification class {$notificationClass} does not exist");
            }

            $notification = new $notificationClass(...$data);
            Notification::send($users, $notification);

            Log::info('Bulk notifications sent', [
                'notification_class' => $notificationClass,
                'user_count' => $users->count()
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send bulk notifications', [
                'notification_class' => $notificationClass,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead(User $user): int
    {
        try {
            $count = $user->unreadNotifications()->count();
            $user->unreadNotifications()->update(['read_at' => now()]);

            Log::info('Marked all notifications as read', [
                'user_id' => $user->id,
                'count' => $count
            ]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Delete old read notifications for a user
     */
    public function deleteOldReadNotifications(User $user, int $daysOld = 30): int
    {
        try {
            $count = $user->readNotifications()
                ->where('read_at', '<', now()->subDays($daysOld))
                ->delete();

            Log::info('Deleted old read notifications', [
                'user_id' => $user->id,
                'count' => $count,
                'days_old' => $daysOld
            ]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Failed to delete old read notifications', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Get notification statistics for a user
     */
    public function getNotificationStats(User $user): array
    {
        try {
            return [
                'total' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
                'read' => 0,
                'today' => 0,
                'this_week' => 0,
                'this_month' => 0,
            ];
        }
    }

    /**
     * Clean up notifications for all users
     */
    public function cleanupNotifications(int $daysOld = 30): array
    {
        try {
            $users = User::all();
            $totalDeleted = 0;
            $processedUsers = 0;

            foreach ($users as $user) {
                $deleted = $this->deleteOldReadNotifications($user, $daysOld);
                $totalDeleted += $deleted;
                $processedUsers++;
            }

            Log::info('Notifications cleanup completed', [
                'processed_users' => $processedUsers,
                'total_deleted' => $totalDeleted,
                'days_old' => $daysOld
            ]);

            return [
                'processed_users' => $processedUsers,
                'total_deleted' => $totalDeleted,
                'days_old' => $daysOld
            ];
        } catch (\Exception $e) {
            Log::error('Failed to cleanup notifications', [
                'error' => $e->getMessage()
            ]);

            return [
                'processed_users' => 0,
                'total_deleted' => 0,
                'days_old' => $daysOld
            ];
        }
    }
}
