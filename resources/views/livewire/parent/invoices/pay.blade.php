<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public Invoice $invoice;

    // Payment details
    #[Rule('required|in:credit_card,bank_transfer,paypal')]
    public string $paymentMethod = 'credit_card';

    #[Rule('required_if:paymentMethod,credit_card')]
    public string $cardNumber = '';

    #[Rule('required_if:paymentMethod,credit_card')]
    public string $cardName = '';

    #[Rule('required_if:paymentMethod,credit_card')]
    public string $expiryDate = '';

    #[Rule('required_if:paymentMethod,credit_card')]
    public string $cvv = '';

    #[Rule('nullable|string')]
    public string $notes = '';

    public function mount(Invoice $invoice): void
    {
        // Ensure parent can only pay invoices for their own children
        $parentProfile = Auth::user()->parentProfile;

        if (!$parentProfile) {
            $this->error('Parent profile not found.');
            $this->redirect(route('parent.invoices.index'));
            return;
        }

        // Get all children IDs for this parent
        $childrenIds = $parentProfile->childProfiles()->pluck('id')->toArray();

        // Check if the invoice belongs to one of the parent's children
        if (!in_array($invoice->child_profile_id, $childrenIds)) {
            $this->error('You do not have permission to pay this invoice.');
            $this->redirect(route('parent.invoices.index'));
            return;
        }

        // Check if the invoice is already paid
        if ($invoice->isPaid()) {
            $this->error('This invoice has already been paid.');
            $this->redirect(route('parent.invoices.show', $invoice));
            return;
        }

        $this->invoice = $invoice->load(['student.user', 'academicYear', 'curriculum']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed payment page for invoice: {$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            ['ip' => request()->ip()]
        );
    }

    #[Title('Pay Invoice')]
    public function title(): string
    {
        return "Pay Invoice: " . $this->invoice->invoice_number;
    }

    // Get amount due
    public function amountDue()
    {
        return $this->invoice->remainingBalance();
    }

    // Process payment
    public function processPayment()
    {
        // Validate based on payment method
        if ($this->paymentMethod === 'credit_card') {
            $this->validate([
                'cardNumber' => 'required|min:16|max:16',
                'cardName' => 'required|string|min:3',
                'expiryDate' => 'required|string|min:5',
                'cvv' => 'required|digits:3',
            ]);
        }

        try {
            // In a real application, this would integrate with a payment gateway
            // For demonstration purposes, we'll create a successful payment record

            // Create payment record
            $payment = Payment::create([
                'invoice_id' => $this->invoice->id,
                'amount' => $this->amountDue(),
                'payment_method' => $this->paymentMethod,
                'transaction_id' => 'TXN-' . time(),
                'status' => 'completed',
                'notes' => $this->notes,
                'payment_date' => now(),
                'created_by' => Auth::id(),
            ]);

            // Mark invoice as paid if full payment
            if ($this->invoice->remainingBalance() <= 0) {
                $this->invoice->markAsPaid();
            }

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'payment',
                "Made payment for invoice: {$this->invoice->invoice_number}",
                Payment::class,
                $payment->id,
                [
                    'invoice_id' => $this->invoice->id,
                    'amount' => $this->amountDue(),
                    'payment_method' => $this->paymentMethod,
                    'status' => 'completed'
                ]
            );

            // Show success message and redirect
            $this->success("Payment of {$this->amountDue()} successfully processed.", redirectTo: route('parent.invoices.show', $this->invoice));

        } catch (\Exception $e) {
            // Log error and show error message
            \Log::error('Payment processing error: ' . $e->getMessage());
            $this->error("Payment processing failed: {$e->getMessage()}");
        }
    }
};?>

<div>
    <!-- Page header -->
    <x-header :title="'Pay Invoice: ' . $invoice->invoice_number" separator>
        <x-slot:actions>
            <x-button label="Back to Invoice" icon="o-arrow-left" link="{{ route('parent.invoices.show', $invoice) }}" />
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-3">
        <!-- PAYMENT FORM -->
        <div class="md:col-span-2">
            <x-card title="Payment Information" separator>
                <x-form wire:submit="processPayment">
                    <!-- Payment Method -->
                    <div class="mb-6">
                        <x-radio
                            label="Select Payment Method"
                            hint="Choose how you'd like to pay"
                            :options="[
                                ['value' => 'credit_card', 'label' => 'Credit Card'],
                                ['value' => 'bank_transfer', 'label' => 'Bank Transfer'],
                                ['value' => 'paypal', 'label' => 'PayPal'],
                            ]"
                            wire:model="paymentMethod"
                        />
                    </div>

                    <!-- Credit Card Details -->
                    <div x-data="{}" x-show="$wire.paymentMethod === 'credit_card'">
                        <div class="grid grid-cols-1 gap-4 mb-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <x-input
                                    label="Card Number"
                                    wire:model="cardNumber"
                                    placeholder="1234 5678 9012 3456"
                                    hint="16-digit number on the front of your card"
                                    icon="o-credit-card"
                                    x-mask="9999 9999 9999 9999"
                                />
                            </div>

                            <div class="md:col-span-2">
                                <x-input
                                    label="Name on Card"
                                    wire:model="cardName"
                                    placeholder="John Smith"
                                    hint="Name as it appears on the card"
                                />
                            </div>

                            <div>
                                <x-input
                                    label="Expiration Date"
                                    wire:model="expiryDate"
                                    placeholder="MM/YY"
                                    hint="Expiry date on the front of your card"
                                    x-mask="99/99"
                                />
                            </div>

                            <div>
                                <x-input
                                    label="CVV"
                                    wire:model="cvv"
                                    placeholder="123"
                                    hint="3-digit security code on the back of your card"
                                    type="password"
                                    x-mask="999"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Bank Transfer Instructions -->
                    <div x-data="{}" x-show="$wire.paymentMethod === 'bank_transfer'">
                        <div class="mb-4 alert alert-info">
                            <x-icon name="o-information-circle" class="w-5 h-5" />
                            <span>Please use the following bank details to make your transfer. Include the invoice number as a reference.</span>
                        </div>

                        <div class="p-4 mb-4 rounded-lg bg-base-200">
                            <div class="grid grid-cols-2 gap-2">
                                <div class="text-sm font-medium">Bank Name:</div>
                                <div>Example Bank</div>

                                <div class="text-sm font-medium">Account Name:</div>
                                <div>School Account</div>

                                <div class="text-sm font-medium">Account Number:</div>
                                <div>1234 5678 9012 3456</div>

                                <div class="text-sm font-medium">Sort Code / Routing:</div>
                                <div>12-34-56</div>

                                <div class="text-sm font-medium">Reference:</div>
                                <div>{{ $invoice->invoice_number }}</div>
                            </div>
                        </div>

                        <div class="alert alert-warning">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5" />
                            <span>After making the transfer, click the 'Submit Payment' button to notify us. We'll verify the payment and update the invoice status.</span>
                        </div>
                    </div>

                    <!-- PayPal Instructions -->
                    <div x-data="{}" x-show="$wire.paymentMethod === 'paypal'">
                        <div class="mb-4 alert alert-info">
                            <x-icon name="o-information-circle" class="w-5 h-5" />
                            <span>You'll be redirected to PayPal to complete your payment.</span>
                        </div>

                        <div class="p-4 mb-4 rounded-lg bg-base-200">
                            <p class="mb-2">Please follow these steps:</p>
                            <ol class="pl-5 space-y-1 list-decimal">
                                <li>Click the 'Pay with PayPal' button below</li>
                                <li>Log in to your PayPal account or pay as a guest</li>
                                <li>Complete the payment</li>
                                <li>You'll be redirected back to confirm your payment</li>
                            </ol>
                        </div>

                        <div class="flex justify-center mb-4">
                            <img src="{{ asset('images/paypal-logo.png') }}" alt="PayPal" class="h-12" onerror="this.src='https://www.paypalobjects.com/webstatic/mktg/logo/pp_cc_mark_111x69.jpg'">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-6">
                        <x-textarea
                            label="Payment Notes (Optional)"
                            wire:model="notes"
                            placeholder="Add any additional information about this payment"
                            rows="3"
                        />
                    </div>

                    <x-slot:actions>
                        <x-button label="Cancel" link="{{ route('parent.invoices.show', $invoice) }}" />
                        <x-button type="submit" label="{{ $paymentMethod === 'paypal' ? 'Pay with PayPal' : 'Submit Payment' }}" icon="o-check" class="btn-primary" spinner="processPayment" />
                    </x-slot:actions>
                </x-form>
            </x-card>
        </div>

        <!-- INVOICE SUMMARY -->
        <div class="md:col-span-1">
            <x-card title="Invoice Summary" separator>
                <div class="space-y-4">
                    <!-- Student Info -->
                    <div class="flex items-center gap-3 mb-4">
                        <div class="avatar">
                            <div class="w-12 h-12 mask mask-squircle">
                                @if ($invoice->student->photo)
                                    <img src="{{ asset('storage/' . $invoice->student->photo) }}" alt="{{ $invoice->student->user?->name ?? 'Student' }}">
                                @else
                                    <img src="{{ $invoice->student->user?->profile_photo_url ?? 'https://ui-avatars.com/api/?name=Student&color=7F9CF5&background=EBF4FF' }}" alt="{{ $invoice->student->user?->name ?? 'Student' }}">
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="font-bold">{{ $invoice->student->user?->name ?? 'Unknown Student' }}</div>
                            <div class="text-sm opacity-70">{{ $invoice->curriculum->name ?? 'Unknown Program' }}</div>
                        </div>
                    </div>

                    <!-- Invoice Details -->
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm text-gray-500">Invoice Number:</div>
                            <div class="font-medium">{{ $invoice->invoice_number }}</div>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm text-gray-500">Invoice Date:</div>
                            <div>{{ $invoice->invoice_date->format('d/m/Y') }}</div>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm text-gray-500">Due Date:</div>
                            <div class="{{ $invoice->isOverdue() ? 'text-error font-medium' : '' }}">
                                {{ $invoice->due_date->format('d/m/Y') }}
                            </div>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-500">Status:</div>
                            <x-badge
                                label="{{ ucfirst($invoice->status) }}"
                                color="{{ match($invoice->status) {
                                    'paid' => 'success',
                                    'pending' => 'warning',
                                    'overdue' => 'error',
                                    default => 'ghost'
                                } }}"
                            />
                        </div>
                    </div>

                    <!-- Payment Summary -->
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm text-gray-500">Invoice Total:</div>
                            <div class="font-medium">{{ number_format($invoice->amount, 2) }}</div>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm text-gray-500">Amount Paid:</div>
                            <div class="text-success">{{ number_format($invoice->amountPaid(), 2) }}</div>
                        </div>
                        <div class="my-2 divider"></div>
                        <div class="flex items-center justify-between">
                            <div class="font-medium">Due Now:</div>
                            <div class="text-lg font-bold text-primary">{{ number_format($amountDue(), 2) }}</div>
                        </div>
                    </div>

                    @if($invoice->isOverdue())
                        <div class="alert alert-error">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5" />
                            <span>This invoice is {{ $invoice->daysOverdue() }} days overdue!</span>
                        </div>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
