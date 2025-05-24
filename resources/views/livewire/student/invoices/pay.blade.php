<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;

new #[Title('Make Payment')] class extends Component {
    use Toast;

    public Invoice $invoice;
    public $canView = false;

    // Payment form data
    public $paymentMethod = 'bank_transfer';
    public $paymentAmount = 0;
    public $paymentDate;
    public $transactionId = '';
    public $notes = '';

    // Payment methods available
    public $paymentMethods = [
        'bank_transfer' => 'Bank Transfer',
        'credit_card' => 'Credit Card',
        'check' => 'Check',
        'cash' => 'Cash',
        'other' => 'Other'
    ];

    // Validation rules
    public function rules()
    {
        return [
            'paymentMethod' => 'required|string',
            'paymentAmount' => 'required|numeric|min:0.01|max:' . $this->invoice->remainingBalance(),
            'paymentDate' => 'required|date|before_or_equal:today',
            'transactionId' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function mount(Invoice $invoice): void
    {
        // Get the client profile associated with this user
        $clientProfile = Auth::user()->clientProfile;

        if (!$clientProfile) {
            $this->error("You don't have a client profile.");
            return redirect()->route('student.invoices.index');
        }

        // Get child profiles associated with this parent
        $childProfiles = \App\Models\ChildProfile::where('parent_profile_id', $clientProfile->id)->get();
        $childProfileIds = $childProfiles->pluck('id')->toArray();

        // Check if the invoice belongs to one of the user's children
        if (!in_array($invoice->child_profile_id, $childProfileIds)) {
            $this->error("You don't have access to this invoice.");
            return redirect()->route('student.invoices.index');
        }

        // Check if the invoice can be paid
        if ($invoice->isPaid()) {
            $this->error("This invoice has already been fully paid.");
            return redirect()->route('student.invoices.show', $invoice->id);
        }

        if ($invoice->status === 'cancelled') {
            $this->error("This invoice has been cancelled and cannot be paid.");
            return redirect()->route('student.invoices.show', $invoice->id);
        }

        $this->canView = true;
        $this->invoice = $invoice;
        $this->invoice->load(['student.user', 'academicYear', 'curriculum', 'items', 'payments']);

        // Initialize form values
        $this->paymentAmount = $invoice->remainingBalance();
        $this->paymentDate = now()->format('Y-m-d');
    }

    // Submit the payment form
    public function submit()
    {
        $this->validate();

        try {
            // Create new payment record
            $payment = new Payment();
            $payment->invoice_id = $this->invoice->id;
            $payment->child_profile_id = $this->invoice->child_profile_id;
            $payment->academic_year_id = $this->invoice->academic_year_id;
            $payment->curriculum_id = $this->invoice->curriculum_id;
            $payment->amount = $this->paymentAmount;
            $payment->payment_date = Carbon::parse($this->paymentDate);
            $payment->payment_method = $this->paymentMethod;
            $payment->transaction_id = $this->transactionId;
            $payment->notes = $this->notes;
            $payment->status = 'pending'; // Default to pending, admins will verify and mark as completed
            $payment->description = 'Payment for invoice #' . $this->invoice->invoice_number;
            $payment->reference_number = 'PAY-' . time() . '-' . $this->invoice->id;
            $payment->created_by = Auth::id();
            $payment->save();

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                'Student submitted payment for invoice',
                Payment::class,
                $payment->id,
                [
                    'invoice_id' => $this->invoice->id,
                    'amount' => $this->paymentAmount,
                    'ip' => request()->ip()
                ]
            );

            // Check if this payment fully pays the invoice
            if ($this->paymentAmount >= $this->invoice->remainingBalance()) {
                // If payment method is online/credit card, mark invoice as paid
                if ($this->paymentMethod === 'credit_card') {
                    $this->invoice->markAsPaid();
                    $payment->markAsCompleted();

                    $this->success('Payment successful! Your invoice has been marked as paid.');
                } else {
                    // For offline payment methods, update invoice status to partially_paid if not already paid
                    if ($this->invoice->status !== 'paid') {
                        $this->invoice->update(['status' => 'partially_paid']);
                    }

                    $this->success('Payment recorded successfully! Your payment will be verified by our staff.');
                }
            } else {
                // Update invoice status to partially_paid
                $this->invoice->update(['status' => 'partially_paid']);

                $this->success('Partial payment recorded successfully! Your payment will be verified by our staff.');
            }

            return redirect()->route('student.invoices.show', $this->invoice->id);

        } catch (\Exception $e) {
            $this->error('An error occurred while processing your payment: ' . $e->getMessage());
        }
    }

    // Calculate totals
    public function getInvoiceTotals()
    {
        $amountPaid = $this->invoice->amountPaid();
        $remainingBalance = $this->invoice->remainingBalance();

        return [
            'total' => $this->invoice->amount,
            'paid' => $amountPaid,
            'remaining' => $remainingBalance,
        ];
    }

    public function with(): array
    {
        return [
            'totals' => $this->getInvoiceTotals(),
        ];
    }
};
?>

<div>
    @if($canView)
        <!-- Page header -->
        <x-header title="Make Payment" separator back-button back-url="{{ route('student.invoices.show', $invoice->id) }}">
            <x-slot:subtitle>
                Invoice #{{ $invoice->invoice_number }} - {{ $invoice->description }}
            </x-slot:subtitle>
        </x-header>

        <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
            <!-- Left Column - Invoice Summary -->
            <div class="col-span-1">
                <x-card title="Invoice Summary">
                    <div class="space-y-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Invoice Number</div>
                            <div class="mt-1 font-semibold">{{ $invoice->invoice_number }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Invoice Date</div>
                            <div class="mt-1">{{ $invoice->invoice_date->format('F d, Y') }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Due Date</div>
                            <div class="mt-1">
                                {{ $invoice->due_date ? $invoice->due_date->format('F d, Y') : 'N/A' }}
                                @if($invoice->isOverdue())
                                    <div class="text-sm text-error">
                                        Overdue by {{ $invoice->daysOverdue() }} days
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Student</div>
                            <div class="mt-1">{{ $invoice->student->user->name }}</div>
                        </div>

                        <div class="my-2 border-t border-gray-200"></div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Total Amount</div>
                            <div class="mt-1 font-bold">{{ number_format($totals['total'], 2) }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Amount Paid</div>
                            <div class="mt-1 text-success">{{ number_format($totals['paid'], 2) }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Remaining Balance</div>
                            <div class="mt-1 font-bold text-error">{{ number_format($totals['remaining'], 2) }}</div>
                        </div>
                    </div>
                </x-card>

                <!-- Payment Instructions -->
                <x-card title="Payment Instructions" class="mt-4">
                    <div class="space-y-4">
                        <div>
                            <p class="text-gray-700">
                                Please complete the form to register your payment. Our team will verify your payment and update the invoice status accordingly.
                            </p>
                        </div>

                        <div class="p-3 rounded-lg bg-base-200">
                            <div class="font-medium">Bank Transfer Details</div>
                            <div class="mt-2 text-sm">
                                <p>Bank Name: Example School Bank</p>
                                <p>Account Name: Example School</p>
                                <p>Account Number: 12345678</p>
                                <p>Reference: {{ $invoice->invoice_number }}</p>
                            </div>
                        </div>

                        <div class="p-3 rounded-lg bg-warning/10">
                            <div class="flex items-start">
                                <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning flex-shrink-0 mt-0.5 mr-2" />
                                <div class="text-sm">
                                    <p class="font-medium">Important</p>
                                    <p class="mt-1">
                                        Please include your invoice number ({{ $invoice->invoice_number }}) as a reference when making your payment.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </x-card>
            </div>

            <!-- Right Column - Payment Form -->
            <div class="col-span-1 md:col-span-2">
                <x-card title="Payment Details">
                    <form wire:submit.prevent="submit">
                        <div class="space-y-6">
                            <!-- Payment Method -->
                            <div>
                                <x-select
                                    label="Payment Method"
                                    wire:model="paymentMethod"
                                    hint="Select how you made the payment"
                                    required
                                >
                                    @foreach($paymentMethods as $value => $label)
                                        <x-select.option :value="$value" :label="$label" />
                                    @endforeach
                                </x-select>
                            </div>

                            <!-- Payment Amount -->
                            <div>
                                <x-input
                                    label="Payment Amount"
                                    wire:model="paymentAmount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    max="{{ $totals['remaining'] }}"
                                    hint="Maximum amount: {{ number_format($totals['remaining'], 2) }}"
                                    required
                                />
                            </div>

                            <!-- Payment Date -->
                            <div>
                                <x-input
                                    label="Payment Date"
                                    wire:model="paymentDate"
                                    type="date"
                                    max="{{ date('Y-m-d') }}"
                                    hint="Date when you made the payment"
                                    required
                                />
                            </div>

                            <!-- Transaction ID -->
                            <div>
                                <x-input
                                    label="Transaction ID / Reference"
                                    wire:model="transactionId"
                                    hint="Reference number from your bank or payment receipt"
                                />
                            </div>

                            <!-- Additional Notes -->
                            <div>
                                <x-textarea
                                    label="Additional Notes"
                                    wire:model="notes"
                                    hint="Any additional information about this payment"
                                    rows="3"
                                />
                            </div>

                            <!-- Payment Policy Acceptance -->
                            <div class="p-4 rounded-lg bg-base-200">
                                <div class="flex items-start">
                                    <div>
                                        <x-checkbox id="accept-policy" required />
                                    </div>
                                    <label for="accept-policy" class="ml-2 text-sm">
                                        I confirm that I have made this payment and the details provided are correct. I understand that the payment will be verified by the school staff before being marked as completed.
                                    </label>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div>
                                <x-button
                                    label="Submit Payment"
                                    icon="o-paper-airplane"
                                    type="submit"
                                    color="primary"
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </form>
                </x-card>
            </div>
        </div>
    @endif
</div>
