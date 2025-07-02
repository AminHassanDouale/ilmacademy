<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public float $amount,
        public string $paymentMethod,
        public string $reference,
        public ?string $actionUrl = null,
        public ?string $currency = 'USD',
        public ?string $description = null
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
        $formattedAmount = $this->formatAmount();

        $mail = (new MailMessage)
            ->subject('Payment Received - Confirmation')
            ->greeting('Payment Confirmation')
            ->line("We have successfully received your payment of {$formattedAmount}")
            ->line("💳 Payment Method: {$this->paymentMethod}")
            ->line("🔗 Reference: {$this->reference}")
            ->line("📅 Date: " . now()->format('M d, Y g:i A'));

        if ($this->description) {
            $mail->line("📝 Description: {$this->description}");
        }

        if ($this->actionUrl) {
            $mail->action('View Payment Details', $this->actionUrl);
        }

        return $mail->line('Thank you for your payment!')
                   ->line('Keep this email for your records.')
                   ->salutation('Best regards, The Finance Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $formattedAmount = $this->formatAmount();

        return [
            'title' => 'Payment Received',
            'message' => "Your payment of {$formattedAmount} has been successfully processed.",
            'action_text' => 'View Payment',
            'action_url' => $this->actionUrl,
            'amount' => $this->amount,
            'formatted_amount' => $formattedAmount,
            'payment_method' => $this->paymentMethod,
            'reference' => $this->reference,
            'currency' => $this->currency,
            'description' => $this->description,
            'type' => 'payment_received',
            'icon' => 'credit-card',
        ];
    }

    /**
     * Format the payment amount with currency.
     */
    private function formatAmount(): string
    {
        return match ($this->currency) {
            'USD' => '$' . number_format($this->amount, 2),
            'EUR' => '€' . number_format($this->amount, 2),
            'GBP' => '£' . number_format($this->amount, 2),
            default => $this->currency . ' ' . number_format($this->amount, 2)
        };
    }

    /**
     * Get the notification's database type.
     */
    public function databaseType(object $notifiable): string
    {
        return 'payment_received';
    }
}
