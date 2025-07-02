<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $senderName,
        public string $subject,
        public string $preview,
        public ?string $actionUrl = null,
        public ?string $senderEmail = null,
        public ?string $messageId = null,
        public string $messageType = 'message',
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
        // For high priority messages, also send via email
        $channels = ['database'];

        if ($this->priority === 'high' || $this->priority === 'urgent') {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $urgencyPrefix = $this->priority === 'urgent' ? 'URGENT: ' :
                        ($this->priority === 'high' ? 'IMPORTANT: ' : '');

        $mail = (new MailMessage)
            ->subject("{$urgencyPrefix}New Message: {$this->subject}")
            ->greeting('New Message Received')
            ->line("You have received a new {$this->messageType} from {$this->senderName}");

        if ($this->senderEmail) {
            $mail->line("📧 From: {$this->senderEmail}");
        }

        $mail->line("📝 Subject: {$this->subject}")
             ->line("👀 Preview: {$this->preview}");

        if ($this->priority === 'urgent') {
            $mail->line('🚨 This message is marked as URGENT - please respond as soon as possible.');
        } elseif ($this->priority === 'high') {
            $mail->line('⚡ This message is marked as HIGH PRIORITY.');
        }

        if ($this->actionUrl) {
            $mail->action('Read Message', $this->actionUrl);
        }

        return $mail->line('Reply when you get a chance!')
                   ->salutation('Best regards, The Messaging Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "New Message from {$this->senderName}",
            'message' => "Subject: {$this->subject}. {$this->preview}",
            'action_text' => 'Read Message',
            'action_url' => $this->actionUrl,
            'sender_name' => $this->senderName,
            'sender_email' => $this->senderEmail,
            'subject' => $this->subject,
            'preview' => $this->preview,
            'message_id' => $this->messageId,
            'message_type' => $this->messageType,
            'priority' => $this->priority,
            'type' => 'new_message',
            'icon' => 'chat-bubble-left',
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'new_message';
    }

    /**
     * Determine if the notification should be sent immediately.
     */
    public function shouldSend(object $notifiable): bool
    {
        // Always send message notifications
        return true;
    }

    /**
     * Get the sound to play for this notification (for real-time notifications).
     */
    public function getSound(): ?string
    {
        return match ($this->priority) {
            'urgent' => 'urgent-alert',
            'high' => 'priority-alert',
            default => 'message-tone'
        };
    }
}
