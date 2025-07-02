<?php

use App\Models\User;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Pay Invoice')] class extends Component {
    use Toast;

    // Current user and invoice
    public User $user;
    public Invoice $invoice;

    // Payment form data
    public float $paymentAmount;
    public string $paymentMethod = 'credit_card';
    public string $cardNumber = '';
    public string $cardExpiry = '';
    public string $cardCvv = '';
    public string $cardName = '';
    public string $billingAddress = '';
    public string $billingCity = '';
    public string $billingState = '';
    public string $billingZip = '';
    public string $paymentNotes = '';

    // Bank transfer details
    public string $bankAccount = '';
    public string $bankRouting = '';

    // Payment state
    public bool $isProcessing = false;
    public bool $showPaymentForm = true;
    public array $paymentResult = [];

    // Financial calculations
    public array $financialSummary = [];
    public array $paymentOptions = [];

    public function mount(Invoice $invoice): void
    {
        $this->user = Auth::user();
        $this->invoice = $invoice->load(['student', 'academicYear', 'curriculum']);

        // Check if user has access to this invoice
        $this->checkAccess();

        // Check if invoice can be paid
        $this->checkPaymentEligibility();

        // Calculate financial summary
        $this->calculateFinancialSummary();

        // Set default payment amount to remaining amount
        $this->paymentAmount = $this->financialSummary['remaining_amount'];

        // Load user's billing information
        $this->loadUserBillingInfo();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'access',
            "Accessed payment page for invoice: {$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            [
                'invoice_id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'invoice_amount' => $this->invoice->amount,
                'remaining_amount' => $this->financialSummary['remaining_amount'],
                'ip' => request()->ip()
            ]
        );
    }

    protected function checkAccess(): void
    {
        $hasAccess = false;

        if ($this->user->hasRole('student')) {
            $hasAccess = $this->invoice->student &&
                        $this->invoice->student->user_id === $this->user->id;
        } elseif ($this->user->hasRole('parent')) {
            $hasAccess = $this->invoice->student &&
                        $this->invoice->student->parent_id === $this->user->id;
        }

        if (!$hasAccess) {
            abort(403, 'You do not have permission to pay this invoice.');
        }
    }

    protected function checkPaymentEligibility(): void
    {
        if (!in_array($this->invoice->status, ['pending', 'sent', 'partially_paid', 'overdue'])) {
            $this->error('This invoice cannot be paid.');
            $this->redirect(route('student.invoices.show', $this->invoice->id));
        }
    }

    protected function calculateFinancialSummary(): void
    {
        $totalAmount = $this->invoice->amount;
        $totalPaid = Payment::where('invoice_id', $this->invoice->id)
            ->where('status', 'completed')
            ->sum('amount');

        $remainingAmount = max(0, $totalAmount - $totalPaid);

        $this->financialSummary = [
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'remaining_amount' => $remainingAmount,
            'can_pay_partial' => $remainingAmount > 10, // Minimum payment amount
            'minimum_payment' => min(10, $remainingAmount),
        ];

        // Generate payment options
        $this->paymentOptions = [
            ['value' => $remainingAmount, 'label' => 'Pay Full Amount', 'description' => $this->formatCurrency($remainingAmount)],
        ];

        if ($this->financialSummary['can_pay_partial'] && $remainingAmount > 50) {
            $halfAmount = round($remainingAmount / 2, 2);
            $this->paymentOptions[] = ['value' => $halfAmount, 'label' => 'Pay Half Amount', 'description' => $this->formatCurrency($halfAmount)];
        }

        if ($this->financialSummary['can_pay_partial']) {
            $this->paymentOptions[] = ['value' => 0, 'label' => 'Custom Amount', 'description' => 'Enter your own amount'];
        }
    }

    protected function loadUserBillingInfo(): void
    {
        // Load user's saved billing information
        $this->cardName = $this->user->name;
        $this->billingAddress = $this->user->address ?? '';

        // Load from client profile if available
        if ($this->user->hasRole('student') && $this->user->clientProfile) {
            $this->billingAddress = $this->user->clientProfile->address ?? $this->billingAddress;
        }
    }

    // Update payment amount when option is selected
    public function setPaymentAmount(float $amount): void
    {
        if ($amount > 0) {
            $this->paymentAmount = $amount;
        }
        // If amount is 0, user will enter custom amount
    }

    // Process the payment
    public function processPayment(): void
    {
        $this->isProcessing = true;

        try {
            // Validate payment data
            $this->validatePayment();

            // Log payment attempt
            ActivityLog::log(
                $this->user->id,
                'payment_attempt',
                "Attempted payment for invoice: {$this->invoice->invoice_number}",
                Invoice::class,
                $this->invoice->id,
                [
                    'payment_amount' => $this->paymentAmount,
                    'payment_method' => $this->paymentMethod,
                    'invoice_number' => $this->invoice->invoice_number
                ]
            );

            DB::beginTransaction();

            // Create payment record
            $payment = $this->createPaymentRecord();

            // Process payment based on method
            $paymentResult = $this->processPaymentMethod($payment);

            if ($paymentResult['success']) {
                // Update payment status
                $payment->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'reference_number' => $paymentResult['reference_number'],
                ]);

                // Update invoice status if fully paid
                $this->updateInvoiceStatus();

                DB::commit();

                // Log successful payment
                ActivityLog::log(
                    $this->user->id,
                    'payment_success',
                    "Successfully paid {$this->formatCurrency($this->paymentAmount)} for invoice: {$this->invoice->invoice_number}",
                    Invoice::class,
                    $this->invoice->id,
                    [
                        'payment_id' => $payment->id,
                        'payment_amount' => $this->paymentAmount,
                        'payment_method' => $this->paymentMethod,
                        'reference_number' => $paymentResult['reference_number']
                    ]
                );

                $this->paymentResult = [
                    'success' => true,
                    'message' => 'Payment processed successfully!',
                    'reference_number' => $paymentResult['reference_number'],
                    'amount' => $this->paymentAmount
                ];

                $this->showPaymentForm = false;
                $this->success('Payment processed successfully! Thank you.');

            } else {
                DB::rollback();
                throw new \Exception($paymentResult['message'] ?? 'Payment processing failed.');
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->isProcessing = false;
            throw $e;
        } catch (\Exception $e) {
            DB::rollback();
            $this->isProcessing = false;

            // Log payment failure
            ActivityLog::log(
                $this->user->id,
                'payment_failed',
                "Payment failed for invoice: {$this->invoice->invoice_number} - {$e->getMessage()}",
                Invoice::class,
                $this->invoice->id,
                [
                    'payment_amount' => $this->paymentAmount,
                    'payment_method' => $this->paymentMethod,
                    'error_message' => $e->getMessage()
                ]
            );

            $this->error('Payment failed: ' . $e->getMessage());
        }

        $this->isProcessing = false;
    }

    protected function validatePayment(): void
    {
        $rules = [
            'paymentAmount' => [
                'required',
                'numeric',
                'min:' . $this->financialSummary['minimum_payment'],
                'max:' . $this->financialSummary['remaining_amount']
            ],
            'paymentMethod' => 'required|in:credit_card,bank_transfer,cash',
        ];

        if ($this->paymentMethod === 'credit_card') {
            $rules = array_merge($rules, [
                'cardNumber' => 'required|string|min:16|max:19',
                'cardExpiry' => 'required|string|size:5',
                'cardCvv' => 'required|string|size:3',
                'cardName' => 'required|string|max:255',
                'billingAddress' => 'required|string|max:500',
            ]);
        } elseif ($this->paymentMethod === 'bank_transfer') {
            $rules = array_merge($rules, [
                'bankAccount' => 'required|string|max:20',
                'bankRouting' => 'required|string|size:9',
            ]);
        }

        $this->validate($rules, [
            'paymentAmount.required' => 'Please enter a payment amount.',
            'paymentAmount.min' => 'Minimum payment amount is ' . $this->formatCurrency($this->financialSummary['minimum_payment']),
            'paymentAmount.max' => 'Payment amount cannot exceed the remaining balance.',
            'cardNumber.required' => 'Please enter your card number.',
            'cardExpiry.required' => 'Please enter the card expiry date.',
            'cardCvv.required' => 'Please enter the CVV code.',
            'cardName.required' => 'Please enter the cardholder name.',
        ]);
    }

    protected function createPaymentRecord(): Payment
    {
        return Payment::create([
            'invoice_id' => $this->invoice->id,
            'child_profile_id' => $this->invoice->child_profile_id,
            'amount' => $this->paymentAmount,
            'payment_method' => $this->paymentMethod,
            'payment_date' => now(),
            'status' => 'pending',
            'notes' => $this->paymentNotes,
            'created_by' => $this->user->id,
        ]);
    }

    protected function processPaymentMethod(Payment $payment): array
    {
        // Simulate payment processing based on method
        switch ($this->paymentMethod) {
            case 'credit_card':
                return $this->processCreditCardPayment($payment);
            case 'bank_transfer':
                return $this->processBankTransfer($payment);
            case 'cash':
                return $this->processCashPayment($payment);
            default:
                return ['success' => false, 'message' => 'Invalid payment method'];
        }
    }

    protected function processCreditCardPayment(Payment $payment): array
    {
        // Simulate credit card processing
        // In real implementation, integrate with payment gateway like Stripe, PayPal, etc.

        // Simulate processing delay
        sleep(1);

        // Simulate 95% success rate
        if (rand(1, 100) <= 95) {
            return [
                'success' => true,
                'reference_number' => 'CC' . strtoupper(Str::random(8)),
                'message' => 'Credit card payment processed successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Credit card payment was declined'
            ];
        }
    }

    protected function processBankTransfer(Payment $payment): array
    {
        // Simulate bank transfer processing
        return [
            'success' => true,
            'reference_number' => 'BT' . strtoupper(Str::random(8)),
            'message' => 'Bank transfer initiated successfully'
        ];
    }

    protected function processCashPayment(Payment $payment): array
    {
        // Cash payments are typically processed manually
        return [
            'success' => true,
            'reference_number' => 'CASH' . strtoupper(Str::random(6)),
            'message' => 'Cash payment recorded'
        ];
    }

    protected function updateInvoiceStatus(): void
    {
        $totalPaid = Payment::where('invoice_id', $this->invoice->id)
            ->where('status', 'completed')
            ->sum('amount');

        if ($totalPaid >= $this->invoice->amount) {
            $this->invoice->update([
                'status' => 'paid',
                'paid_date' => now()
            ]);
        } elseif ($totalPaid > 0) {
            $this->invoice->update([
                'status' => 'partially_paid'
            ]);
        }
    }

    // Navigation methods
    public function redirectToInvoice(): void
    {
        $this->redirect(route('student.invoices.show', $this->invoice->id));
    }

    public function redirectToInvoices(): void
    {
        $this->redirect(route('student.invoices.index'));
    }

    public function makeAnotherPayment(): void
    {
        $this->showPaymentForm = true;
        $this->paymentResult = [];
        $this->calculateFinancialSummary();
        $this->paymentAmount = $this->financialSummary['remaining_amount'];
    }

    // Helper functions
    public function formatCurrency(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }

    public function formatDate($date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->format('M d, Y');
        } catch (\Exception $e) {
            return 'Date not available';
        }
    }

    public function isInvoiceOverdue(): bool
    {
        return \Carbon\Carbon::parse($this->invoice->due_date)->isPast() &&
               $this->invoice->status !== 'paid';
    }

    public function getPaymentMethodIcon(string $method): string
    {
        return match($method) {
            'credit_card' => 'o-credit-card',
            'bank_transfer' => 'o-building-library',
            'cash' => 'o-banknotes',
            default => 'o-currency-dollar'
        };
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>

