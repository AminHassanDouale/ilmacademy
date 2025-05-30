<?php

use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;

new #[Title('Invoice Details')] class extends Component {
    use Toast;

    public Invoice $invoice;
    public $canView = false;

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

        $this->canView = true;
        $this->invoice = $invoice;
        $this->invoice->load(['student.user', 'academicYear', 'curriculum', 'items', 'payments']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            'Student viewed invoice details',
            Invoice::class,
            $invoice->id,
            ['ip' => request()->ip()]
        );
    }

    // Get payment methods (in a real app, these would come from a settings table or payment gateway)
    public function getPaymentMethods()
    {
        return [
            [
                'id' => 'bank_transfer',
                'name' => 'Bank Transfer',
                'instructions' => 'Please transfer to Bank Name: Example Bank, Account Number: 12345678, Reference: ' . $this->invoice->invoice_number
            ],
            [
                'id' => 'credit_card',
                'name' => 'Credit Card',
                'instructions' => 'Click the "Pay Now" button to make a secure credit card payment.'
            ],
            [
                'id' => 'check',
                'name' => 'Check',
                'instructions' => 'Please make checks payable to "Your School Name" and send to our address with the invoice number in the memo line.'
            ]
        ];
    }

    // Calculate invoice totals
    public function getInvoiceTotals()
    {
        $amountPaid = $this->invoice->amountPaid();
        $remainingBalance = $this->invoice->remainingBalance();

        return [
            'subtotal' => $this->invoice->items->sum(function($item) {
                return $item->amount * $item->quantity;
            }),
            'total' => $this->invoice->amount,
            'paid' => $amountPaid,
            'remaining' => $remainingBalance,
            'is_paid' => $this->invoice->isPaid(),
        ];
    }

    // Initiate payment (in a real app, this would redirect to a payment processor)
    public function initiatePayment()
    {
        if ($this->invoice->isPaid()) {
            $this->error('This invoice has already been paid.');
            return;
        }

        // Redirect to the payment creation page
        return redirect()->route('student.payments.create', ['invoice' => $this->invoice->id]);
    }

    // Download invoice (in a real app, this would generate a PDF)
    public function downloadInvoice()
    {
        $this->info('Invoice download functionality would be implemented here.');

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'download',
            'Student downloaded invoice',
            Invoice::class,
            $this->invoice->id,
            ['ip' => request()->ip()]
        );
    }

    public function with(): array
    {
        return [
            'paymentMethods' => $this->getPaymentMethods(),
            'totals' => $this->getInvoiceTotals(),
        ];
    }
};
?>

<div>
    @if($canView)
        <!-- Page header -->
        <x-header title="Invoice #{{ $invoice->invoice_number }}" separator back-button back-url="{{ route('student.invoices.index') }}">
            <x-slot:subtitle>
                {{ $invoice->description }}
            </x-slot:subtitle>

            <x-slot:actions>
                <div class="flex items-center space-x-2">
                    <x-button
                        label="Download"
                        icon="o-arrow-down-tray"
                        wire:click="downloadInvoice"
                    />

                    @if(!$totals['is_paid'] && $invoice->status !== 'cancelled')
                        <x-button
                            label="Pay Now"
                            icon="o-credit-card"
                            color="primary"
                            wire:click="initiatePayment"
                        />
                    @endif
                </div>
            </x-slot:actions>
        </x-header>

        <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-3">
            <!-- Left Column - Invoice Info -->
            <div class="col-span-1">
                <!-- Status Card -->
                <x-card>
                    <div class="flex items-center justify-between mb-4">
                        <div class="font-medium">Status</div>
                        <div>
                            @if($invoice->status === 'paid')
                                <x-badge label="Paid" color="success" size="lg" />
                            @elseif($invoice->status === 'pending')
                                @if($invoice->isOverdue())
                                    <x-badge label="Overdue" color="error" size="lg" />
                                @else
                                    <x-badge label="Pending" color="warning" size="lg" />
                                @endif
                            @elseif($invoice->status === 'cancelled')
                                <x-badge label="Cancelled" color="neutral" size="lg" />
                            @elseif($invoice->status === 'partially_paid')
                                <x-badge label="Partially Paid" color="info" size="lg" />
                            @else
                                <x-badge label="{{ ucfirst($invoice->status) }}" color="neutral" size="lg" />
                            @endif
                        </div>
                    </div>

                    <div class="space-y-4">
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

                        @if($invoice->academicYear)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Academic Year</div>
                                <div class="mt-1">{{ $invoice->academicYear->name }}</div>
                            </div>
                        @endif

                        @if($invoice->curriculum)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Curriculum</div>
                                <div class="mt-1">{{ $invoice->curriculum->name }}</div>
                            </div>
                        @endif
                    </div>
                </x-card>

                <!-- Payment Summary -->
                <x-card title="Payment Summary" class="mt-4">
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <span>Total Amount</span>
                            <span class="font-bold">{{ number_format($totals['total'], 2) }}</span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span>Amount Paid</span>
                            <span class="text-success">{{ number_format($totals['paid'], 2) }}</span>
                        </div>

                        <div class="my-2 border-t border-gray-200"></div>

                        <div class="flex items-center justify-between">
                            <span class="font-medium">Remaining Balance</span>
                            <span class="font-bold {{ $totals['remaining'] > 0 ? 'text-error' : '' }}">
                                {{ number_format($totals['remaining'], 2) }}
                            </span>
                        </div>
                    </div>

                    @if(!$totals['is_paid'] && $invoice->status !== 'cancelled')
                        <div class="mt-4">
                            <x-button
                                label="Make Payment"
                                icon="o-credit-card"
                                color="primary"
                                class="w-full"
                                wire:click="initiatePayment"
                            />
                        </div>
                    @endif
                </x-card>

                <!-- Payment Methods -->
                @if(!$totals['is_paid'] && $invoice->status !== 'cancelled')
                    <x-card title="Payment Methods" class="mt-4">
                        <div class="space-y-4">
                            @foreach($paymentMethods as $method)
                                <div class="p-3 rounded-lg bg-base-200">
                                    <div class="font-medium">{{ $method['name'] }}</div>
                                    <div class="mt-1 text-sm">
                                        {{ $method['instructions'] }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif
            </div>

            <!-- Right Column - Invoice Details -->
            <div class="col-span-1 md:col-span-2">
                <!-- Invoice Items -->
                <x-card title="Invoice Items">
                    <div class="overflow-x-auto">
                        @if(count($invoice->items) > 0)
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Description</th>
                                        <th class="text-right">Quantity</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoice->items as $item)
                                        <tr>
                                            <td>{{ $item->name }}</td>
                                            <td>{{ $item->description }}</td>
                                            <td class="text-right">{{ $item->quantity }}</td>
                                            <td class="text-right">{{ number_format($item->amount, 2) }}</td>
                                            <td class="font-bold text-right">{{ number_format($item->totalAmount(), 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="font-bold text-right">Subtotal</td>
                                        <td class="font-bold text-right">{{ number_format($totals['subtotal'], 2) }}</td>
                                    </tr>
                                    @if($totals['subtotal'] != $totals['total'])
                                        <tr>
                                            <td colspan="4" class="font-bold text-right">Total</td>
                                            <td class="font-bold text-right">{{ number_format($totals['total'], 2) }}</td>
                                        </tr>
                                    @endif
                                </tfoot>
                            </table>
                        @else
                            <div class="p-4 text-center">
                                <p class="text-gray-500">No invoice items found.</p>
                            </div>
                        @endif
                    </div>
                </x-card>

                <!-- Payment History -->
                @if(count($invoice->payments) > 0)
                    <x-card title="Payment History" class="mt-4">
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Payment ID</th>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoice->payments as $payment)
                                        <tr>
                                            <td>{{ $payment->payment_id }}</td>
                                            <td>{{ Carbon\Carbon::parse($payment->payment_date)->format('M d, Y') }}</td>
                                            <td>{{ ucfirst($payment->payment_method) }}</td>
                                            <td>
                                                @if($payment->status === 'completed')
                                                    <x-badge label="Completed" color="success" />
                                                @elseif($payment->status === 'pending')
                                                    <x-badge label="Pending" color="warning" />
                                                @elseif($payment->status === 'failed')
                                                    <x-badge label="Failed" color="error" />
                                                @else
                                                    <x-badge label="{{ ucfirst($payment->status) }}" color="neutral" />
                                                @endif
                                            </td>
                                            <td class="font-bold text-right">{{ number_format($payment->amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-card>
                @endif

                <!-- Invoice Notes -->
                @if($invoice->notes)
                    <x-card title="Notes" class="mt-4">
                        <div class="p-3 rounded-lg bg-base-200">
                            {{ $invoice->notes }}
                        </div>
                    </x-card>
                @endif
            </div>
        </div>
    @endif
</div>
