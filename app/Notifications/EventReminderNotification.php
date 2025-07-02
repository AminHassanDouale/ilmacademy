<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $eventTitle,
        public string $eventDate,
        public ?string $eventLocation = null,
        public ?string $actionUrl = null,
        public ?string $reminderType = 'upcoming'
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
        $mail = (new MailMessage)
            ->subject("Event Reminder: {$this->eventTitle}")
            ->greeting('Event Reminder')
            ->line("Don't forget about the upcoming event: {$this->eventTitle}")
            ->line("📅 Date: {$this->eventDate}");

        if ($this->eventLocation) {
            $mail->line("📍 Location: {$this->eventLocation}");
        }

        if ($this->actionUrl) {
            $mail->action('View Event Details', $this->actionUrl);
        }

        return $mail->line('We look forward to seeing you there!')
                   ->salutation('Best regards, The Events Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = "Don't forget about the upcoming event on {$this->eventDate}";
        if ($this->eventLocation) {
            $message .= " at {$this->eventLocation}";
        }
        $message .= '.';

        return [
            'title' => "Event Reminder: {$this->eventTitle}",
            'message' => $message,
            'action_text' => 'View Event',
            'action_url' => $this->actionUrl,
            'event_title' => $this->eventTitle,
            'event_date' => $this->eventDate,
            'event_location' => $this->eventLocation,
            'reminder_type' => $this->reminderType,
            'type' => 'event_reminder',
            'icon' => 'calendar',
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'event_reminder';
    }
}
