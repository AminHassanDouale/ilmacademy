

<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Pay Invoice')] class extends Component {
    use Toast;

    // Model instance
    public Invoice $invoice;

    // Form data
    public string $payment_method = 'credit_card';
    public string $amount = '';
    public string $cardholder_name = '';
    public string $card_number = '';
    public string $expiry_month = '';
    public string $expiry_year = '';
    public string $cvv = '';
    public string $billing_address = '';
    public string $billing_city = '';
    public string $billing_state = '';
    public string $billing_zip = '';
    public bool $save_payment_method = false;
    public bool $terms_accepted = false;

    // Payment options
    protected array $validPaymentMethods = ['credit_card', 'bank_transfer', 'check'];

    // Calculated amounts
    public float $amountDue = 0;
    public float $processingFee = 0;
    public float $totalAmount = 0;

    public function mount(Invoice $invoice): void
    {
        // Ensure the authenticated parent owns this invoice
        if (!$invoice->student || $invoice->student->parent_id !== Auth::id()) {
            abort(403, 'You do not have permission to pay this invoice.');
        }

        // Ensure invoice is payable
        if (!in_array($invoice->status, ['pending', 'partially_paid', 'overdue'])) {
            $this->error('This invoice is not available for payment.');
            $this->redirect(route('parent.invoices.show', $invoice->id));
            return;
        }

        $this->invoice = $invoice->load(['student', 'payments']);

        // Calculate amounts
        $this->calculateAmounts();

        // Set default payment amount to full remaining amount
        $this->amount = (string) $this->amountDue;

        Log::info('Parent Invoice Payment Component Mounted', [
            'parent_id' => Auth::id(),
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'amount_due' => $this->amountDue,
            'ip' => request()->ip()
        ]);

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            "Accessed payment page for invoice: {$invoice->invoice_number}",
            $invoice,
            [
                'invoice_number' => $invoice->invoice_number,
                'invoice_id' => $invoice->id,
                'amount_due' => $this->amountDue,
            ]
        );
    }

    protected function calculateAmounts(): void
    {
        $totalPaid = $this->invoice->payments()->where('status', Payment::STATUS_COMPLETED)->sum('amount');
        $this->amountDue = $this->invoice->amount - $totalPaid;

        // Calculate processing fee (2.9% for credit card)
        $this->processingFee = $this->payment_method === 'credit_card' ? $this->amountDue * 0.029 : 0;
        $this->totalAmount = $this->amountDue + $this->processingFee;
    }

    public function updatedPaymentMethod(): void
    {
        $this->calculateAmounts();
    }

    public function updatedAmount(): void
    {
        if (is_numeric($this->amount)) {
            $amount = (float) $this->amount;
            $this->processingFee = $this->payment_method === 'credit_card' ? $amount * 0.029 : 0;
            $this->totalAmount = $amount + $this->processingFee;
        }
    }

    // Process payment
    public function processPayment(): void
    {
        Log::info('Payment Processing Started', [
            'parent_id' => Auth::id(),
            'invoice_id' => $this->invoice->id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
        ]);

        try {
            // Validate form data
            Log::debug('Starting Payment Validation');

            $validated = $this->validate([
                'amount' => [
                    'required',
                    'numeric',
                    'min:0.01',
                    'max:' . $this->amountDue
                ],
                'payment_method' => 'required|string|in:' . implode(',', $this->validPaymentMethods),
                'terms_accepted' => 'accepted',
            ] + ($this->payment_method === 'credit_card' ? [
                'cardholder_name' => 'required|string|max:255',
                'card_number' => 'required|string|min:13|max:19',
                'expiry_month' => 'required|string|size:2',
                'expiry_year' => 'required|string|size:4',
                'cvv' => 'required|string|min:3|max:4',
                'billing_address' => 'required|string|max:255',
                'billing_city' => 'required|string|max:100',
                'billing_state' => 'required|string|max:50',
                'billing_zip' => 'required|string|max:10',
            ] : []), [
                'amount.required' => 'Please enter a payment amount.',
                'amount.numeric' => 'Payment amount must be a valid number.',
                'amount.min' => 'Payment amount must be at least $0.01.',
                'amount.max' => 'Payment amount cannot exceed the amount due.',
                'payment_method.required' => 'Please select a payment method.',
                'payment_method.in' => 'Please select a valid payment method.',
                'terms_accepted.accepted' => 'You must accept the terms and conditions.',
                'cardholder_name.required' => 'Please enter the cardholder name.',
                'card_number.required' => 'Please enter the card number.',
                'card_number.min' => 'Card number must be at least 13 digits.',
                'expiry_month.required' => 'Please enter the expiry month.',
                'expiry_year.required' => 'Please enter the expiry year.',
                'cvv.required' => 'Please enter the CVV.',
                'billing_address.required' => 'Please enter the billing address.',
                'billing_city.required' => 'Please enter the billing city.',
                'billing_state.required' => 'Please enter the billing state.',
                'billing_zip.required' => 'Please enter the billing ZIP code.',
            ]);

            Log::info('Payment Validation Passed', ['validated_data' => collect($validated)->except(['card_number', 'cvv'])->toArray()]);

            // Additional validation for credit card expiry
            if ($this->payment_method === 'credit_card') {
                $expiryDate = \Carbon\Carbon::createFromFormat('Y-m', $validated['expiry_year'] . '-' . $validated['expiry_month']);
                if ($expiryDate->endOfMonth()->isPast()) {
                    $this->addError('expiry_month', 'Card has expired.');
                    return;
                }
            }

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create payment record using your Payment model structure
            $paymentData = [
                'invoice_id' => $this->invoice->id,
                'child_profile_id' => $this->invoice->child_profile_id,
                'academic_year_id' => $this->invoice->academic_year_id,
                'curriculum_id' => $this->invoice->curriculum_id,
                'amount' => (float) $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'status' => Payment::STATUS_COMPLETED, // In real implementation, start as PENDING
                'transaction_id' => 'TXN_' . strtoupper(uniqid()) . '_' . time(),
                'reference_number' => 'PAY_' . $this->invoice->invoice_number . '_' . time(),
                'payment_date' => now(),
                'description' => "Online payment for invoice {$this->invoice->invoice_number}",
                'created_by' => Auth::id(),
                'notes' => $this->buildPaymentNotes($validated),
            ];

            // Create payment record
            Log::debug('Creating Payment Record');
            $payment = Payment::create($paymentData);
            Log::info('Payment Created Successfully', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'transaction_id' => $payment->transaction_id,
                'reference_number' => $payment->reference_number,
            ]);

            // Update invoice status
            $this->updateInvoiceStatus($payment);

            // Log activity
            Log::debug('Logging Payment Activity');
            ActivityLog::logActivity(
                Auth::id(),
                'payment',
                "Made payment of $" . number_format((float) $validated['amount'], 2) . " for invoice {$this->invoice->invoice_number}",
                $this->invoice,
                [
                    'payment_id' => $payment->id,
                    'amount' => (float) $validated['amount'],
                    'payment_method' => $validated['payment_method'],
                    'transaction_id' => $payment->transaction_id,
                    'reference_number' => $payment->reference_number,
                    'invoice_number' => $this->invoice->invoice_number,
                ]
            );

            DB::commit();
            Log::info('Payment Transaction Committed');

            // Show success message
            $this->success("Payment of $" . number_format((float) $validated['amount'], 2) . " has been processed successfully!");

            // Redirect to invoice details
            Log::info('Redirecting to Invoice Show Page', [
                'invoice_id' => $this->invoice->id,
                'route' => 'parent.invoices.show'
            ]);

            $this->redirect(route('parent.invoices.show', $this->invoice->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Payment Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => collect([
                    'amount' => $this->amount,
                    'payment_method' => $this->payment_method,
                    'cardholder_name' => $this->cardholder_name,
                ])->toArray()
            ]);

            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment Processing Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'invoice_id' => $this->invoice->id,
                'form_data' => [
                    'amount' => $this->amount,
                    'payment_method' => $this->payment_method,
                ]
            ]);

            $this->error("Payment processing failed: {$e->getMessage()}");
        }
    }

    protected function buildPaymentNotes(array $validated): string
    {
        $notes = [];
        $notes[] = ucfirst(str_replace('_', ' ', $this->payment_method)) . " payment";

        if ($this->payment_method === 'credit_card') {
            $notes[] = "Processing fee: $" . number_format($this->processingFee, 2);
            if (isset($validated['cardholder_name'])) {
                $notes[] = "Cardholder: " . $validated['cardholder_name'];
            }
            if (isset($validated['card_number'])) {
                $notes[] = "Card ending in: " . substr($validated['card_number'], -4);
            }
        }

        return implode(' | ', $notes);
    }

    protected function updateInvoiceStatus(Payment $payment): void
    {
        $totalPaid = $this->invoice->payments()->where('status', Payment::STATUS_COMPLETED)->sum('amount');

        if ($totalPaid >= $this->invoice->amount) {
            $this->invoice->update([
                'status' => 'paid',
                'paid_date' => now(),
            ]);
        } elseif ($totalPaid > 0) {
            $this->invoice->update(['status' => 'partially_paid']);
        }

        Log::info('Invoice Status Updated', [
            'invoice_id' => $this->invoice->id,
            'total_paid' => $totalPaid,
            'invoice_amount' => $this->invoice->amount,
            'new_status' => $this->invoice->fresh()->status,
        ]);
    }

    // Get payment method options
    public function getPaymentMethodOptionsProperty(): array
    {
        return [
            'credit_card' => 'Credit/Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'check' => 'Check',
        ];
    }

    // Get month options
    public function getMonthOptionsProperty(): array
    {
        return collect(range(1, 12))->map(function ($month) {
            return [
                'value' => str_pad($month, 2, '0', STR_PAD_LEFT),
                'label' => str_pad($month, 2, '0', STR_PAD_LEFT)
            ];
        })->toArray();
    }

    // Get year options
    public function getYearOptionsProperty(): array
    {
        $currentYear = now()->year;
        return collect(range($currentYear, $currentYear + 20))->map(function ($year) {
            return [
                'value' => (string) $year,
                'label' => (string) $year
            ];
        })->toArray();
    }

    public function with(): array
    {
        return [
            // Empty array - we use computed properties instead
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Pay Invoice: {{ $invoice->invoice_number }}" separator>
        <x-slot:actions>
            <x-button
                label="Back to Invoice"
                icon="o-arrow-left"
                link="{{ route('parent.invoices.show', $invoice->id) }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Payment Form -->
        <div class="lg:col-span-2">
            <form wire:submit="processPayment" class="space-y-6">
                <!-- Invoice Summary -->
                <x-card title="Invoice Summary">
                    <div class="p-4 rounded-lg bg-gray-50">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <div class="text-lg font-semibold">{{ $invoice->invoice_number }}</div>
                                <div class="text-sm text-gray-600">{{ $invoice->student->full_name ?? 'Unknown Student' }}</div>
                                <div class="text-sm text-gray-600">{{ $invoice->curriculum->name ?? 'Program Fee' }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold">${{ number_format($invoice->amount, 2) }}</div>
                                <div class="text-sm text-gray-600">Total Invoice</div>
                            </div>
                        </div>

                        @if($invoice->payments()->where('status', 'completed')->count() > 0)
                            <div class="pt-3 border-t">
                                <div class="flex justify-between text-sm">
                                    <span>Previously Paid:</span>
                                    <span class="text-green-600">${{ number_format($invoice->payments()->where('status', 'completed')->sum('amount'), 2) }}</span>
                                </div>
                                <div class="flex justify-between mt-2 text-lg font-semibold">
                                    <span>Amount Due:</span>
                                    <span class="text-red-600">${{ number_format($amountDue, 2) }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>

                <!-- Payment Amount -->
                <x-card title="Payment Amount">
                    <div class="space-y-4">
                        <div>
                            <x-input
                                label="Payment Amount"
                                wire:model.live="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                max="{{ $amountDue }}"
                                placeholder="Enter payment amount"
                                required
                            />
                            <div class="mt-1 text-sm text-gray-500">
                                Maximum amount: ${{ number_format($amountDue, 2) }}
                            </div>
                            @error('amount')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex gap-2">
                            <x-button
                                label="Pay Full Amount"
                                wire:click="$set('amount', '{{ $amountDue }}')"
                                class="btn-sm btn-outline"
                                type="button"
                            />
                            <x-button
                                label="Pay Half"
                                wire:click="$set('amount', '{{ $amountDue / 2 }}')"
                                class="btn-sm btn-outline"
                                type="button"
                            />
                        </div>
                    </div>
                </x-card>

                <!-- Payment Method -->
                <x-card title="Payment Method">
                    <div class="space-y-4">
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Select Payment Method *</label>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                @foreach($this->paymentMethodOptions as $value => $label)
                                    <label class="flex items-center p-3 border-2 rounded-lg cursor-pointer hover:bg-gray-50 {{ $payment_method === $value ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }}">
                                        <input
                                            type="radio"
                                            wire:model.live="payment_method"
                                            value="{{ $value }}"
                                            class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                        />
                                        <div class="ml-3">
                                            <div class="font-medium">{{ $label }}</div>
                                            @if($value === 'credit_card')
                                                <div class="text-xs text-gray-500">2.9% processing fee</div>
                                            @elseif($value === 'bank_transfer')
                                                <div class="text-xs text-gray-500">3-5 business days</div>
                                            @elseif($value === 'check')
                                                <div class="text-xs text-gray-500">Mail payment instructions</div>
                                            @endif
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                            @error('payment_method')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </x-card>

                <!-- Credit Card Details -->
                @if($payment_method === 'credit_card')
                    <x-card title="Credit Card Information">
                        <div class="space-y-4">
                            <!-- Cardholder Name -->
                            <div>
                                <x-input
                                    label="Cardholder Name"
                                    wire:model.live="cardholder_name"
                                    placeholder="Full name as it appears on card"
                                    required
                                />
                                @error('cardholder_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Card Number -->
                            <div>
                                <x-input
                                    label="Card Number"
                                    wire:model.live="card_number"
                                    placeholder="1234 5678 9012 3456"
                                    maxlength="19"
                                    required
                                />
                                @error('card_number')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Expiry and CVV -->
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Expiry Month *</label>
                                    <select
                                        wire:model.live="expiry_month"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    >
                                        <option value="">MM</option>
                                        @foreach($this->monthOptions as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('expiry_month')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label class="block mb-2 text-sm font-medium text-gray-700">Expiry Year *</label>
                                    <select
                                        wire:model.live="expiry_year"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                        required
                                    >
                                        <option value="">YYYY</option>
                                        @foreach($this->yearOptions as $option)
                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                    @error('expiry_year')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <x-input
                                        label="CVV"
                                        wire:model.live="cvv"
                                        placeholder="123"
                                        maxlength="4"
                                        required
                                    />
                                    @error('cvv')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <!-- Billing Address -->
                            <div class="pt-4 border-t">
                                <h4 class="mb-4 text-lg font-medium">Billing Address</h4>

                                <div class="space-y-4">
                                    <div>
                                        <x-input
                                            label="Address"
                                            wire:model.live="billing_address"
                                            placeholder="123 Main Street"
                                            required
                                        />
                                        @error('billing_address')
                                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <div>
                                            <x-input
                                                label="City"
                                                wire:model.live="billing_city"
                                                placeholder="City"
                                                required
                                            />
                                            @error('billing_city')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <x-input
                                                label="State"
                                                wire:model.live="billing_state"
                                                placeholder="State"
                                                required
                                            />
                                            @error('billing_state')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <x-input
                                                label="ZIP Code"
                                                wire:model.live="billing_zip"
                                                placeholder="12345"
                                                required
                                            />
                                            @error('billing_zip')
                                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-card>
                @elseif($payment_method === 'bank_transfer')
                    <x-card title="Bank Transfer Instructions">
                        <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                            <div class="space-y-3 text-sm">
                                <div><strong>Bank Name:</strong> First National Bank</div>
                                <div><strong>Account Name:</strong> School Payment Account</div>
                                <div><strong>Account Number:</strong> 123-456-789</div>
                                <div><strong>Routing Number:</strong> 987654321</div>
                                <div><strong>Reference:</strong> {{ $invoice->invoice_number }}</div>
                            </div>
                            <div class="p-3 mt-4 border border-yellow-200 rounded-md bg-yellow-50">
                                <p class="text-sm text-yellow-800">
                                    <strong>Important:</strong> Please include the invoice number ({{ $invoice->invoice_number }})
                                    as the reference when making the transfer.
                                </p>
                            </div>
                        </div>
                    </x-card>
                @elseif($payment_method === 'check')
                    <x-card title="Check Payment Instructions">
                        <div class="p-4 border border-gray-200 rounded-lg bg-gray-50">
                            <div class="space-y-3 text-sm">
                                <div><strong>Make Check Payable To:</strong> School District Payment Office</div>
                                <div>
                                    <strong>Mail To:</strong><br>
                                    School District Payment Office<br>
                                    123 Education Lane<br>
                                    School City, SC 12345
                                </div>
                                <div><strong>Memo Line:</strong> {{ $invoice->invoice_number }}</div>
                            </div>
                            <div class="p-3 mt-4 border border-yellow-200 rounded-md bg-yellow-50">
                                <p class="text-sm text-yellow-800">
                                    <strong>Important:</strong> Please write the invoice number ({{ $invoice->invoice_number }})
                                    in the memo line.
                                </p>
                            </div>
                        </div>
                    </x-card>
                @endif

                <!-- Terms and Conditions -->
                <x-card title="Terms and Conditions">
                    <div class="space-y-4">
                        <label class="flex items-start">
                            <input
                                type="checkbox"
                                wire:model.live="terms_accepted"
                                class="w-4 h-4 mt-1 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                required
                            />
                            <span class="ml-2 text-sm text-gray-700">
                                I accept the <a href="#" class="text-blue-600 hover:underline">Terms and Conditions</a>
                                and <a href="#" class="text-blue-600 hover:underline">Privacy Policy</a>.
                                I understand that payments are processed securely and that processing fees may apply for credit card transactions.
                            </span>
                        </label>
                        @error('terms_accepted')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </x-card>

                <!-- Submit Button -->
                <div class="flex justify-end pt-6">
                    <x-button
                        label="Cancel"
                        link="{{ route('parent.invoices.show', $invoice->id) }}"
                        class="mr-2"
                    />
                    <x-button
                        label="Process Payment"
                        icon="o-credit-card"
                        type="submit"
                        class="btn-primary"
                    />
                </div>
            </form>
        </div>

        <!-- Right column (1/3) - Payment Summary -->
        <div class="space-y-6">
            <!-- Payment Summary -->
            <x-card title="Payment Summary">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Payment Amount</span>
                        <span class="font-semibold">${{ is_numeric($amount) ? number_format((float)$amount, 2) : '0.00' }}</span>
                    </div>

                    @if($payment_method === 'credit_card' && is_numeric($amount))
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Processing Fee (2.9%)</span>
                            <span class="font-semibold">${{ number_format($processingFee, 2) }}</span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between pt-2 border-t">
                        <span class="text-lg font-semibold">Total</span>
                        <span class="text-lg font-bold text-blue-600">
                            ${{ is_numeric($amount) ? number_format($totalAmount, 2) : '0.00' }}
                        </span>
                    </div>
                </div>
            </x-card>

            <!-- Invoice Details -->
            <x-card title="Invoice Details">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-medium text-gray-500">Invoice Number</div>
                        <div class="font-mono">{{ $invoice->invoice_number }}</div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Student</div>
                        <div>{{ $invoice->student->full_name ?? 'Unknown Student' }}</div>
                    </div>

                    @if($invoice->curriculum)
                        <div>
                            <div class="font-medium text-gray-500">Program</div>
                            <div>{{ $invoice->curriculum->name }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Due Date</div>
                        <div class="{{ $invoice->due_date && $invoice->due_date < now() ? 'text-red-600 font-semibold' : '' }}">
                            {{ $invoice->due_date ? $invoice->due_date->format('M d, Y') : 'Not set' }}
                        </div>
                    </div>

                    <div>
                        <div class="font-medium text-gray-500">Total Invoice</div>
                        <div class="font-semibold">${{ number_format($invoice->amount, 2) }}</div>
                    </div>

                    @if($invoice->payments()->where('status', 'completed')->count() > 0)
                        <div>
                            <div class="font-medium text-gray-500">Previously Paid</div>
                            <div class="text-green-600">${{ number_format($invoice->payments()->where('status', 'completed')->sum('amount'), 2) }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="font-medium text-gray-500">Amount Remaining</div>
                        <div class="font-semibold text-red-600">${{ number_format($amountDue, 2) }}</div>
                    </div>
                </div>
            </x-card>

            <!-- Security Notice -->
            <x-card title="Security Notice" class="border-green-200 bg-green-50">
                <div class="space-y-3 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-shield-check" class="w-5 h-5 text-green-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-green-800">Secure Payment</div>
                            <p class="text-green-700">All payment information is encrypted and processed securely using industry-standard security protocols.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-lock-closed" class="w-5 h-5 text-green-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-green-800">Data Protection</div>
                            <p class="text-green-700">We never store your complete credit card information on our servers.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-check-circle" class="w-5 h-5 text-green-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-green-800">PCI Compliant</div>
                            <p class="text-green-700">Our payment processing is PCI DSS compliant for your security.</p>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Help & Support -->
            <x-card title="Help & Support">
                <div class="space-y-3 text-sm">
                    <div>
                        <div class="font-semibold">Payment Issues?</div>
                        <p class="text-gray-600">If you experience any problems with your payment, please contact our support team immediately.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Processing Time</div>
                        <ul class="mt-2 space-y-1 text-gray-600">
                            <li><strong>Credit Card:</strong> Immediate</li>
                            <li><strong>Bank Transfer:</strong> 3-5 business days</li>
                            <li><strong>Check:</strong> 7-10 business days</li>
                        </ul>
                    </div>

                    <div>
                        <div class="font-semibold">Refund Policy</div>
                        <p class="text-gray-600">Refunds are processed according to our school policy. Please contact the office for refund requests.</p>
                    </div>

                    <div class="pt-3 border-t">
                        <x-button
                            label="Contact Support"
                            icon="o-chat-bubble-left-right"
                            class="w-full btn-outline btn-sm"
                            disabled
                        />
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
