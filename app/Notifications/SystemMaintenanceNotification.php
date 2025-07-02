<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemMaintenanceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $maintenanceDate,
        public string $startTime,
        public string $endTime,
        public string $description,
        public ?string $impact = null,
        public ?array $affectedServices = null,
        public string $maintenanceType = 'scheduled'
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
        $isEmergency = $this->maintenanceType === 'emergency';
        $subject = $isEmergency ? 'URGENT: Emergency System Maintenance' : 'Scheduled System Maintenance';

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('System Maintenance Notice');

        if ($isEmergency) {
            $mail->line('⚠️ EMERGENCY MAINTENANCE NOTICE ⚠️');
        }

        $mail->line("We will be performing {$this->maintenanceType} maintenance on {$this->maintenanceDate} from {$this->startTime} to {$this->endTime}.")
             ->line("🔧 Description: {$this->description}");

        if ($this->impact) {
            $mail->line("📋 Expected Impact: {$this->impact}");
        }

        if ($this->affectedServices && count($this->affectedServices) > 0) {
            $mail->line("🎯 Affected Services:")
                 ->line('• ' . implode("\n• ", $this->affectedServices));
        }

        $mail->line('During this time, some features may be temporarily unavailable.');

        if ($isEmergency) {
            $mail->line('We sincerely apologize for the short notice and any inconvenience this may cause.');
        } else {
            $mail->line('We apologize for any inconvenience this may cause.');
        }

        return $mail->line('We will notify you once the maintenance is complete.')
                   ->salutation('Best regards, The Technical Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = "{$this->maintenanceType} maintenance scheduled for {$this->maintenanceDate} from {$this->startTime} to {$this->endTime}. {$this->description}";

        return [
            'title' => $this->maintenanceType === 'emergency' ? 'Emergency System Maintenance' : 'Scheduled System Maintenance',
            'message' => $message,
            'maintenance_date' => $this->maintenanceDate,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'description' => $this->description,
            'impact' => $this->impact,
            'affected_services' => $this->affectedServices,
            'maintenance_type' => $this->maintenanceType,
            'type' => 'system_maintenance',
            'icon' => 'cog-6-tooth',
            'priority' => $this->maintenanceType === 'emergency' ? 'high' : 'normal',
        ];
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'system_maintenance';
    }

    /**
     * Determine the notification delay based on maintenance type.
     */
    public function delay(object $notifiable): ?\DateTimeInterface
    {
        // Emergency maintenance should be sent immediately
        if ($this->maintenanceType === 'emergency') {
            return null;
        }

        // Scheduled maintenance can be delayed slightly to batch with other notifications
        return now()->addMinutes(5);
    }
}
