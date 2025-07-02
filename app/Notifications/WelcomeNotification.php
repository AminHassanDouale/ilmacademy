<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $userName,
        public ?string $actionUrl = null
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to our platform!')
            ->greeting("Hello {$this->userName}!")
            ->line('Welcome to our platform. We\'re excited to have you on board.')
            ->action('Get Started', $this->actionUrl ?: url('/dashboard'))
            ->line('If you have any questions, feel free to contact our support team.');
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Welcome to our platform!',
            'message' => "Hello {$this->userName}! Welcome to our platform. We're excited to have you on board.",
            'action_text' => 'Get Started',
            'action_url' => $this->actionUrl ?: url('/dashboard'),
            'user_name' => $this->userName,
        ];
    }
}

class EventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $eventTitle,
        public string $eventDate,
        public ?string $eventLocation = null,
        public ?string $actionUrl = null
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Reminder: {$this->eventTitle}")
            ->greeting('Event Reminder')
            ->line("Don't forget about the upcoming event: {$this->eventTitle}")
            ->line("Date: {$this->eventDate}");

        if ($this->eventLocation) {
            $mail->line("Location: {$this->eventLocation}");
        }

        if ($this->actionUrl) {
            $mail->action('View Event Details', $this->actionUrl);
        }

        return $mail->line('We look forward to seeing you there!');
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => "Event Reminder: {$this->eventTitle}",
            'message' => "Don't forget about the upcoming event on {$this->eventDate}" .
                        ($this->eventLocation ? " at {$this->eventLocation}" : '') . '.',
            'action_text' => 'View Event',
            'action_url' => $this->actionUrl,
            'event_title' => $this->eventTitle,
            'event_date' => $this->eventDate,
            'event_location' => $this->eventLocation,
        ];
    }
}

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public float $amount,
        public string $paymentMethod,
        public string $reference,
        public ?string $actionUrl = null
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment Received')
            ->greeting('Payment Confirmation')
            ->line("We have successfully received your payment of $" . number_format($this->amount, 2))
            ->line("Payment Method: {$this->paymentMethod}")
            ->line("Reference: {$this->reference}")
            ->action('View Payment Details', $this->actionUrl ?: url('/payments'))
            ->line('Thank you for your payment!');
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Payment Received',
            'message' => "Your payment of $" . number_format($this->amount, 2) . " has been successfully processed.",
            'action_text' => 'View Payment',
            'action_url' => $this->actionUrl,
            'amount' => $this->amount,
            'payment_method' => $this->paymentMethod,
            'reference' => $this->reference,
        ];
    }
}

class AssignmentDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $assignmentTitle,
        public string $dueDate,
        public string $courseName,
        public ?string $actionUrl = null
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Assignment Due: {$this->assignmentTitle}")
            ->greeting('Assignment Reminder')
            ->line("Your assignment '{$this->assignmentTitle}' for {$this->courseName} is due on {$this->dueDate}.")
            ->action('View Assignment', $this->actionUrl ?: url('/assignments'))
            ->line('Make sure to submit it on time!');
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => "Assignment Due: {$this->assignmentTitle}",
            'message' => "Your assignment for {$this->courseName} is due on {$this->dueDate}.",
            'action_text' => 'View Assignment',
            'action_url' => $this->actionUrl,
            'assignment_title' => $this->assignmentTitle,
            'due_date' => $this->dueDate,
            'course_name' => $this->courseName,
        ];
    }
}

class SystemMaintenanceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $maintenanceDate,
        public string $startTime,
        public string $endTime,
        public string $description
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Scheduled System Maintenance')
            ->greeting('System Maintenance Notice')
            ->line("We will be performing scheduled maintenance on {$this->maintenanceDate} from {$this->startTime} to {$this->endTime}.")
            ->line("Description: {$this->description}")
            ->line('During this time, some features may be temporarily unavailable.')
            ->line('We apologize for any inconvenience this may cause.');
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => 'Scheduled System Maintenance',
            'message' => "System maintenance scheduled for {$this->maintenanceDate} from {$this->startTime} to {$this->endTime}. {$this->description}",
            'maintenance_date' => $this->maintenanceDate,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'description' => $this->description,
        ];
    }
}

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $senderName,
        public string $subject,
        public string $preview,
        public ?string $actionUrl = null
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New Message: {$this->subject}")
            ->greeting('New Message Received')
            ->line("You have received a new message from {$this->senderName}")
            ->line("Subject: {$this->subject}")
            ->line("Preview: {$this->preview}")
            ->action('Read Message', $this->actionUrl ?: url('/messages'))
            ->line('Reply when you get a chance!');
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => "New Message from {$this->senderName}",
            'message' => "Subject: {$this->subject}. {$this->preview}",
            'action_text' => 'Read Message',
            'action_url' => $this->actionUrl,
            'sender_name' => $this->senderName,
            'subject' => $this->subject,
            'preview' => $this->preview,
        ];
    }
}
