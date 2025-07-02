<?php

use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Invoice Details')] class extends Component {
    use Toast;

    // Model instance
    public Invoice $invoice;

    // Activity logs
    public $activityLogs = [];

    // Invoice items
    public $invoiceItems = [];

    public function mount(Invoice $invoice): void
    {
        // Ensure the authenticated parent owns this invoice
        if (!$invoice->student || $invoice->student->parent_id !== Auth::id()) {
            abort(403, 'You do not have permission to view this invoice.');
        }

        $this->invoice = $invoice->load([
            'student',
            'academicYear',
            'curriculum',
            'programEnrollment',
            'payments',
            'createdBy'
        ]);

        // Load invoice items if available
    protected function loadInvoiceItems(): void
    {
        try {
            if (class_exists('App\Models\InvoiceItem')) {
                $this->invoiceItems = $this->invoice->items ?? collect();
            } else {
                $this->invoiceItems = collect();
            }
        } catch (\Exception $e) {
            $this->invoiceItems = collect();
        }
    }

    // Load activity logs for this invoice
    protected function loadActivityLogs(): void
    {
        try {
            $this->activityLogs = ActivityLog::with('user')
                ->where(function ($query) {
                    $query->where('subject_type', Invoice::class)
                          ->where('subject_id', $this->invoice->id);
                })
                ->orWhere(function ($query) {
                    $query->where('loggable_type', Invoice::class)
                          ->where('loggable_id', $this->invoice->id);
                })
                ->orderByDesc('created_at')
                ->limit(15)
                ->get();
        } catch (\Exception $e) {
            $this->activityLogs = collect();
        }
    }

    // Navigation to payment page
    public function redirectToPay(): void
    {
        $this->redirect(route('parent.invoices.pay', $this->invoice->id));
    }

    // Helper function to get status color
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'draft' => 'bg-gray-100 text-gray-600',
            'sent' => 'bg-blue-100 text-blue-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'partially_paid' => 'bg-orange-100 text-orange-800',
            'paid' => 'bg-green-100 text-green-800',
            'overdue' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    // Helper function to get status icon
    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'draft' => 'o-document',
            'sent' => 'o-paper-airplane',
            'pending' => 'o-clock',
            'partially_paid' => 'o-banknotes',
            'paid' => 'o-check-circle',
            'overdue' => 'o-exclamation-triangle',
            'cancelled' => 'o-x-circle',
            default => 'o-document'
        };
    }

    // Get activity icon
    public function getActivityIcon(string $action): string
    {
        return match($action) {
            'create' => 'o-plus',
            'update' => 'o-pencil',
            'view' => 'o-eye',
            'payment' => 'o-credit-card',
            'send' => 'o-paper-airplane',
            'cancel' => 'o-x-circle',
            default => 'o-information-circle'
        };
    }

    // Get activity color
    public function getActivityColor(string $action): string
    {
        return match($action) {
            'create' => 'text-green-600',
            'update' => 'text-blue-600',
            'view' => 'text-gray-600',
            'payment' => 'text-emerald-600',
            'send' => 'text-purple-600',
            'cancel' => 'text-red-600',
            default => 'text-gray-600'
        };
    }

    // Format date for display
    public function formatDate($date): string
    {
        if (!$date) {
            return 'Not set';
        }

        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }

        return $date->format('M d, Y');
    }

    // Check if invoice is payable
    public function isPayable(): bool
    {
        return in_array($this->invoice->status, ['pending', 'partially_paid', 'overdue']);
    }

    // Calculate payment statistics
    public function getPaymentStats(): array
    {
        $totalPaid = $this->invoice->payments->sum('amount');
        $remaining = $this->invoice->amount - $totalPaid;
        $percentPaid = $this->invoice->amount > 0 ? ($totalPaid / $this->invoice->amount) * 100 : 0;

        return [
            'total_amount' => $this->invoice->amount,
            'amount_paid' => $totalPaid,
            'amount_remaining' => $remaining,
            'percent_paid' => round($percentPaid, 1),
            'payment_count' => $this->invoice->payments->count(),
        ];
    }

    public function with(): array
    {
        return [
            'paymentStats' => $this->getPaymentStats(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Invoice: {{ $invoice->invoice_number }}" separator>
        <x-slot:middle class="!justify-end">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($invoice->status) }}">
                <x-icon name="{{ $this->getStatusIcon($invoice->status) }}" class="w-4 h-4 mr-1" />
                {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
            </span>
            @if($invoice->status === 'overdue')
                <span class="inline-flex items-center px-3 py-1 ml-2 text-sm font-medium text-red-800 bg-red-100 rounded-full">
                    URGENT
                </span>
            @endif
        </x-slot:middle>

        <x-slot:actions>
            @if($this->isPayable())
                <x-button
                    label="Pay Now"
                    icon="o-credit-card"
                    wire:click="redirectToPay"
                    class="{{ $invoice->status === 'overdue' ? 'btn-error' : 'btn-primary' }}"
                />
            @endif

            <x-button
                label="Back to Invoices"
                icon="o-arrow-left"
                link="{{ route('parent.invoices.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Invoice Details -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Invoice Information -->
            <x-card title="Invoice Information">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Invoice Number</div>
                        <div class="font-mono text-lg font-semibold">{{ $invoice->invoice_number }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Amount</div>
                        <div class="text-2xl font-bold {{ $invoice->status === 'paid' ? 'text-green-600' : ($invoice->status === 'overdue' ? 'text-red-600' : 'text-gray-900') }}">
                            ${{ number_format($invoice->amount, 2) }}
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Invoice Date</div>
                        <div class="text-lg">{{ $this->formatDate($invoice->invoice_date) }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Due Date</div>
                        <div class="text-lg {{ $invoice->due_date && $invoice->due_date < now() && $invoice->status !== 'paid' ? 'text-red-600 font-semibold' : '' }}">
                            {{ $this->formatDate($invoice->due_date) }}
                            @if($invoice->due_date && $invoice->due_date < now() && $invoice->status !== 'paid')
                                <span class="text-sm text-red-600">({{ $invoice->due_date->diffForHumans() }})</span>
                            @endif
                        </div>
                    </div>

                    @if($invoice->paid_date)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Paid Date</div>
                            <div class="text-lg text-green-600">{{ $this->formatDate($invoice->paid_date) }}</div>
                        </div>
                    @endif

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div class="mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium {{ $this->getStatusColor($invoice->status) }}">
                                <x-icon name="{{ $this->getStatusIcon($invoice->status) }}" class="w-4 h-4 mr-1" />
                                {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}
                            </span>
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Student and Program Information -->
            <x-card title="Student & Program Details">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <div class="text-sm font-medium text-gray-500">Student</div>
                        <div class="flex items-center mt-1">
                            <div class="mr-3 avatar placeholder">
                                <div class="w-10 h-10 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                    <span class="text-sm font-bold">{{ $invoice->student->initials ?? '??' }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="font-semibold">{{ $invoice->student->full_name ?? 'Unknown Student' }}</div>
                                @if($invoice->student && $invoice->student->age)
                                    <div class="text-sm text-gray-500">{{ $invoice->student->age }} years old</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if($invoice->curriculum)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Program</div>
                            <div class="text-lg">{{ $invoice->curriculum->name }}</div>
                            @if($invoice->curriculum->code)
                                <div class="text-sm text-gray-500">{{ $invoice->curriculum->code }}</div>
                            @endif
                        </div>
                    @endif

                    @if($invoice->academicYear)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Academic Year</div>
                            <div class="text-lg">{{ $invoice->academicYear->name }}</div>
                        </div>
                    @endif

                    @if($invoice->createdBy)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Created By</div>
                            <div class="text-lg">{{ $invoice->createdBy->name }}</div>
                        </div>
                    @endif
                </div>

                @if($invoice->description)
                    <div class="pt-6 mt-6 border-t">
                        <div class="mb-2 text-sm font-medium text-gray-500">Description</div>
                        <div class="p-3 rounded-md bg-gray-50">
                            {{ $invoice->description }}
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Invoice Items -->
            @if($invoiceItems->count() > 0)
                <x-card title="Invoice Items">
                    <div class="overflow-x-auto">
                        <table class="table w-full">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Description</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoiceItems as $item)
                                    <tr>
                                        <td>
                                            <div class="font-medium">{{ $item->name }}</div>
                                        </td>
                                        <td>
                                            <div class="text-sm text-gray-600">{{ $item->description ?: '-' }}</div>
                                        </td>
                                        <td class="text-center">
                                            {{ $item->quantity ?? 1 }}
                                        </td>
                                        <td class="font-mono text-right">
                                            ${{ number_format($item->amount, 2) }}
                                        </td>
                                        <td class="font-mono font-semibold text-right">
                                            ${{ number_format(($item->amount * ($item->quantity ?? 1)), 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2">
                                    <td colspan="4" class="font-semibold text-right">Total Amount:</td>
                                    <td class="font-mono text-lg font-bold text-right">
                                        ${{ number_format($invoice->amount, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </x-card>
            @endif

            <!-- Payment History -->
            @if($invoice->payments->count() > 0)
                <x-card title="Payment History">
                    <div class="space-y-3">
                        @foreach($invoice->payments as $payment)
                            <div class="flex items-center justify-between p-3 border border-green-200 rounded-md bg-green-50">
                                <div>
                                    <div class="font-medium text-green-800">
                                        Payment #{{ $payment->id ?? 'N/A' }}
                                    </div>
                                    <div class="text-sm text-green-600">
                                        {{ $this->formatDate($payment->created_at) }}
                                        @if($payment->payment_method ?? false)
                                            • {{ ucfirst($payment->payment_method) }}
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-green-600">
                                        ${{ number_format($payment->amount, 2) }}
                                    </div>
                                    @if($payment->status ?? false)
                                        <div class="text-xs text-green-500">
                                            {{ ucfirst($payment->status) }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Payment Summary -->
                    <div class="p-3 pt-4 mt-4 border-t rounded-md bg-gray-50">
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div>
                                <div class="text-sm text-gray-500">Total Invoice</div>
                                <div class="font-semibold">${{ number_format($paymentStats['total_amount'], 2) }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Amount Paid</div>
                                <div class="font-semibold text-green-600">${{ number_format($paymentStats['amount_paid'], 2) }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-500">Remaining</div>
                                <div class="font-semibold {{ $paymentStats['amount_remaining'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                                    ${{ number_format($paymentStats['amount_remaining'], 2) }}
                                </div>
                            </div>
                        </div>

                        @if($paymentStats['amount_remaining'] > 0)
                            <div class="mt-3">
                                <div class="w-full h-2 bg-gray-200 rounded-full">
                                    <div class="h-2 bg-green-600 rounded-full" style="width: {{ $paymentStats['percent_paid'] }}%"></div>
                                </div>
                                <div class="mt-1 text-sm text-center text-gray-600">
                                    {{ $paymentStats['percent_paid'] }}% paid
                                </div>
                            </div>
                        @endif
                    </div>
                </x-card>
            @endif

            <!-- Notes -->
            @if($invoice->notes)
                <x-card title="Notes">
                    <div class="p-4 rounded-md bg-gray-50">
                        <p class="text-sm">{{ $invoice->notes }}</p>
                    </div>
                </x-card>
            @endif

            <!-- Activity Log -->
            @if($activityLogs->count() > 0)
                <x-card title="Invoice Activity">
                    <div class="space-y-4 overflow-y-auto max-h-96">
                        @foreach($activityLogs as $log)
                            <div class="flex items-start pb-4 space-x-4 border-b border-gray-100 last:border-b-0">
                                <div class="flex-shrink-0">
                                    <div class="flex items-center justify-center w-8 h-8 bg-gray-100 rounded-full">
                                        <x-icon name="{{ $this->getActivityIcon($log->action ?? $log->activity_type) }}" class="w-4 h-4 {{ $this->getActivityColor($log->action ?? $log->activity_type) }}" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm">
                                        <span class="font-medium text-gray-900">
                                            {{ $log->user ? $log->user->name : 'System' }}
                                        </span>
                                        <span class="text-gray-600">{{ $log->description ?? $log->activity_description }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $log->created_at->diffForHumans() }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column (1/3) - Actions and Summary -->
        <div class="space-y-6">
            <!-- Payment Actions -->
            <x-card title="Payment Actions">
                <div class="space-y-3">
                    @if($this->isPayable())
                        <x-button
                            label="Pay Full Amount"
                            icon="o-credit-card"
                            wire:click="redirectToPay"
                            class="w-full {{ $invoice->status === 'overdue' ? 'btn-error' : 'btn-primary' }}"
                        />

                        @if($paymentStats['amount_remaining'] != $paymentStats['total_amount'])
                            <x-button
                                label="Pay Remaining (${{ number_format($paymentStats['amount_remaining'], 2) }})"
                                icon="o-banknotes"
                                wire:click="redirectToPay"
                                class="w-full btn-warning"
                            />
                        @endif
                    @else
                        <div class="p-4 text-center rounded-lg bg-green-50">
                            <x-icon name="o-check-circle" class="w-8 h-8 mx-auto mb-2 text-green-600" />
                            <div class="text-sm font-medium text-green-800">Invoice Paid</div>
                            <div class="text-xs text-green-600">Thank you for your payment!</div>
                        </div>
                    @endif

                    <div class="pt-3 border-t">
                        <x-button
                            label="Download PDF"
                            icon="o-document-arrow-down"
                            class="w-full btn-outline"
                            disabled
                        />
                        <p class="mt-1 text-xs text-gray-500">PDF download coming soon!</p>
                    </div>
                </div>
            </x-card>

            <!-- Payment Summary -->
            <x-card title="Payment Summary">
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Invoice Amount</span>
                        <span class="font-semibold">${{ number_format($paymentStats['total_amount'], 2) }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Amount Paid</span>
                        <span class="font-semibold text-green-600">${{ number_format($paymentStats['amount_paid'], 2) }}</span>
                    </div>

                    <div class="flex items-center justify-between pt-2 border-t">
                        <span class="text-sm text-gray-600">Amount Due</span>
                        <span class="font-bold {{ $paymentStats['amount_remaining'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                            ${{ number_format($paymentStats['amount_remaining'], 2) }}
                        </span>
                    </div>

                    @if($paymentStats['payment_count'] > 0)
                        <div class="flex items-center justify-between">
                            <span class="text-sm text-gray-600">Payments Made</span>
                            <span class="font-semibold">{{ $paymentStats['payment_count'] }}</span>
                        </div>
                    @endif

                    @if($paymentStats['amount_remaining'] > 0)
                        <div class="pt-2 border-t">
                            <div class="mb-2 text-sm text-gray-600">Payment Progress</div>
                            <div class="w-full h-3 bg-gray-200 rounded-full">
                                <div class="h-3 transition-all duration-300 bg-blue-600 rounded-full" style="width: {{ $paymentStats['percent_paid'] }}%"></div>
                            </div>
                            <div class="mt-1 text-xs text-center text-gray-600">
                                {{ $paymentStats['percent_paid'] }}% completed
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Invoice Status -->
            <x-card title="Invoice Status">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Current Status</span>
                        <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $invoice->status)) }}</span>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Due Date</span>
                        <span class="font-semibold {{ $invoice->due_date && $invoice->due_date < now() && $invoice->status !== 'paid' ? 'text-red-600' : '' }}">
                            {{ $this->formatDate($invoice->due_date) }}
                        </span>
                    </div>

                    @if($invoice->due_date && $invoice->status !== 'paid')
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">
                                @if($invoice->due_date > now())
                                    Days Until Due
                                @else
                                    Days Overdue
                                @endif
                            </span>
                            <span class="font-semibold {{ $invoice->due_date < now() ? 'text-red-600' : 'text-green-600' }}">
                                {{ abs($invoice->due_date->diffInDays(now())) }}
                            </span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between">
                        <span class="text-gray-600">Created</span>
                        <span class="font-semibold">{{ $this->formatDate($invoice->created_at) }}</span>
                    </div>
                </div>
            </x-card>

            <!-- Quick Links -->
            <x-card title="Related">
                <div class="space-y-2">
                    <x-button
                        label="All Invoices"
                        icon="o-document-text"
                        link="{{ route('parent.invoices.index') }}"
                        class="justify-start w-full btn-ghost btn-sm"
                    />

                    @if($invoice->student)
                        <x-button
                            label="Child Profile"
                            icon="o-user"
                            link="{{ route('parent.children.show', $invoice->student->id) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    @if($invoice->programEnrollment)
                        <x-button
                            label="View Enrollment"
                            icon="o-academic-cap"
                            link="{{ route('parent.enrollments.show', $invoice->programEnrollment->id) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif

                    @if($invoice->student)
                        <x-button
                            label="Other Invoices for {{ $invoice->student->first_name ?? 'Child' }}"
                            icon="o-document-duplicate"
                            link="{{ route('parent.invoices.index', ['child' => $invoice->student->id]) }}"
                            class="justify-start w-full btn-ghost btn-sm"
                        />
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</div>
