<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssignmentDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $assignmentTitle,
        public string $dueDate,
        public string $courseName,
        public ?string $actionUrl = null,
        public ?string $timeRemaining = null,
        public ?string $instructions = null,
        public string $priority = 'normal'
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $urgencyPrefix = $this->priority === 'urgent' ? 'URGENT: ' : '';

        $mail = (new MailMessage)
            ->subject("{$urgencyPrefix}Assignment Due: {$this->assignmentTitle}")
            ->greeting('Assignment Reminder')
            ->line("Your assignment '{$this->assignmentTitle}' for {$this->courseName} is due on {$this->dueDate}.");

        if ($this->timeRemaining) {
            $mail->line("⏰ Time remaining: {$this->timeRemaining}");
        }

        if ($this->instructions) {
            $mail->line("📋 Instructions: {$this->instructions}");
        }

        if ($this->actionUrl) {
            $mail->action('View Assignment', $this->actionUrl);
        }

        $mail->line('Make sure to submit it on time to avoid any penalties.');

        if ($this->priority === 'urgent') {
            $mail->line('⚠️ This is an urgent reminder - the deadline is approaching soon!');
        }

        return $mail->salutation('Best regards, Your Academic Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = "Your assignment for {$this->courseName} is due on {$this->dueDate}";
        if ($this->timeRemaining) {
            $message .= ". Time remaining: {$this->timeRemaining}";
        }
        $message .= '.';

        return [
            'title' => "Assignment Due: {$this->assignmentTitle}",
            'message' => $message,
            'action_text' => 'View Assignment',
            'action_url' => $this->actionUrl,
            'assignment_title' => $this->assignmentTitle,
            'due_date' => $this->dueDate,
            'course_name' => $this->courseName,
            'time_remaining' => $this->timeRemaining,
            'instructions' => $this->instructions,
            'priority' => $this->priority,
            'type' => 'assignment_due',
            'icon' => 'document-text',
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'assignment_due';
    }

    /**
     * Determine if the notification should be sent immediately.
     */
    public function shouldSend(object $notifiable): bool
    {
        // Don't send if the assignment is already overdue by more than a week
        $dueDateTime = \Carbon\Carbon::parse($this->dueDate);
        return $dueDateTime->isAfter(now()->subWeek());
    }
}
