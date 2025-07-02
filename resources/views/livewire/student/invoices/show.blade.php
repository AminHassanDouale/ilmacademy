<?php

use App\Models\User;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Invoice Details')] class extends Component {
    use Toast;

    // Current user and invoice
    public User $user;
    public Invoice $invoice;

    // Related data
    public array $invoiceItems = [];
    public array $payments = [];
    public array $paymentHistory = [];

    // Tab management
    public string $activeTab = 'overview';

    // Financial calculations
    public array $financialSummary = [];

    public function mount(Invoice $invoice): void
    {
        $this->user = Auth::user();
        $this->invoice = $invoice->load([
            'student',
            'academicYear',
            'curriculum',
            'programEnrollment',
            'createdBy'
        ]);

        // Check if user has access to this invoice
        $this->checkAccess();

        // Load related data
        $this->loadInvoiceItems();
        $this->loadPayments();
        $this->calculateFinancialSummary();

        // Log activity
        ActivityLog::log(
            $this->user->id,
            'view',
            "Viewed invoice: {$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            [
                'invoice_id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'invoice_amount' => $this->invoice->amount,
                'invoice_status' => $this->invoice->status,
                'ip' => request()->ip()
            ]
        );
    }

    protected function checkAccess(): void
    {
        $hasAccess = false;

        if ($this->user->hasRole('student')) {
            // Student can only view their own invoices
            $hasAccess = $this->invoice->student &&
                        $this->invoice->student->user_id === $this->user->id;
        } elseif ($this->user->hasRole('parent')) {
            // Parent can view their children's invoices
            $hasAccess = $this->invoice->student &&
                        $this->invoice->student->parent_id === $this->user->id;
        }

        if (!$hasAccess) {
            abort(403, 'You do not have permission to view this invoice.');
        }
    }

    protected function loadInvoiceItems(): void
    {
        try {
            if (class_exists(InvoiceItem::class)) {
                $this->invoiceItems = InvoiceItem::where('invoice_id', $this->invoice->id)
                    ->orderBy('created_at')
                    ->get()
                    ->toArray();
            } else {
                // Fallback: Create default items from invoice description
                $this->invoiceItems = [
                    [
                        'id' => 1,
                        'name' => $this->invoice->description ?: 'Tuition Fee',
                        'description' => 'Academic program fee',
                        'quantity' => 1,
                        'amount' => $this->invoice->amount,
                        'total_amount' => $this->invoice->amount
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->invoiceItems = [];
        }
    }

    protected function loadPayments(): void
    {
        try {
            if (class_exists(Payment::class)) {
                $this->payments = Payment::where('invoice_id', $this->invoice->id)
                    ->orderBy('payment_date', 'desc')
                    ->get()
                    ->toArray();

                // Also load broader payment history for this student
                $this->paymentHistory = Payment::where('child_profile_id', $this->invoice->child_profile_id)
                    ->where('status', 'completed')
                    ->orderBy('payment_date', 'desc')
                    ->limit(5)
                    ->get()
                    ->toArray();
            }
        } catch (\Exception $e) {
            $this->payments = [];
            $this->paymentHistory = [];
        }
    }

    protected function calculateFinancialSummary(): void
    {
        $totalAmount = $this->invoice->amount;
        $totalPaid = collect($this->payments)
            ->where('status', 'completed')
            ->sum('amount');

        $remainingAmount = max(0, $totalAmount - $totalPaid);
        $paymentProgress = $totalAmount > 0 ? ($totalPaid / $totalAmount) * 100 : 0;

        $this->financialSummary = [
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'remaining_amount' => $remainingAmount,
            'payment_progress' => round($paymentProgress, 1),
            'is_fully_paid' => $remainingAmount <= 0,
            'is_overdue' => $this->isInvoiceOverdue(),
            'days_until_due' => $this->getDaysUntilDue(),
            'payment_count' => count($this->payments),
        ];
    }

    // Set active tab
    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    // Navigation methods
    public function redirectToInvoices(): void
    {
        $this->redirect(route('student.invoices.index'));
    }

    public function redirectToPayment(): void
    {
        if ($this->canPayInvoice()) {
            $this->redirect(route('student.invoices.pay', $this->invoice->id));
        } else {
            $this->error('This invoice cannot be paid at the moment.');
        }
    }

    public function redirectToEnrollment(): void
    {
        if ($this->invoice->program_enrollment_id) {
            $this->redirect(route('student.enrollments.show', $this->invoice->program_enrollment_id));
        }
    }

    // Download invoice (placeholder for PDF generation)
    public function downloadInvoice(): void
    {
        // Log the download action
        ActivityLog::log(
            $this->user->id,
            'download',
            "Downloaded invoice: {$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            [
                'invoice_number' => $this->invoice->invoice_number,
                'download_type' => 'pdf'
            ]
        );

        $this->success("Invoice {$this->invoice->invoice_number} download started...");
        // In real implementation, generate and download PDF
    }

    // Helper functions
    public function getStatusColor(string $status): string
    {
        return match($status) {
            'draft' => 'bg-gray-100 text-gray-800',
            'sent' => 'bg-blue-100 text-blue-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'partially_paid' => 'bg-orange-100 text-orange-800',
            'paid' => 'bg-green-100 text-green-800',
            'overdue' => 'bg-red-100 text-red-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function getPaymentStatusColor(string $status): string
    {
        return match($status) {
            'completed' => 'bg-green-100 text-green-800',
            'pending' => 'bg-yellow-100 text-yellow-800',
            'failed' => 'bg-red-100 text-red-800',
            'refunded' => 'bg-blue-100 text-blue-800',
            'cancelled' => 'bg-gray-100 text-gray-600',
            default => 'bg-gray-100 text-gray-600'
        };
    }

    public function getActualStatus(): string
    {
        if ($this->isInvoiceOverdue() && $this->invoice->status !== 'paid') {
            return 'overdue';
        }
        return $this->invoice->status;
    }

    public function isInvoiceOverdue(): bool
    {
        if ($this->invoice->status === 'paid') {
            return false;
        }

        if (in_array($this->invoice->status, ['pending', 'sent', 'partially_paid'])) {
            return \Carbon\Carbon::parse($this->invoice->due_date)->isPast();
        }

        return $this->invoice->status === 'overdue';
    }

    public function getDaysUntilDue(): int
    {
        try {
            return \Carbon\Carbon::parse($this->invoice->due_date)->diffInDays(now(), false);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function canPayInvoice(): bool
    {
        return in_array($this->invoice->status, ['pending', 'sent', 'partially_paid', 'overdue']) &&
               $this->financialSummary['remaining_amount'] > 0;
    }

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

    public function formatDateTime($date): string
    {
        try {
            return \Carbon\Carbon::parse($date)->format('M d, Y \a\t g:i A');
        } catch (\Exception $e) {
            return 'Date not available';
        }
    }

    public function getPaymentMethodIcon(string $method): string
    {
        return match(strtolower($method)) {
            'credit_card', 'card' => 'o-credit-card',
            'bank_transfer', 'transfer' => 'o-building-library',
            'cash' => 'o-banknotes',
            'check', 'cheque' => 'o-document-text',
            'online' => 'o-computer-desktop',
            default => 'o-currency-dollar'
        };
    }

    public function getUrgencyMessage(): string
    {
        if ($this->invoice->status === 'paid') {
            return 'Invoice has been paid in full.';
        }

        $daysUntilDue = $this->getDaysUntilDue();

        if ($daysUntilDue > 0) {
            return "Invoice is overdue by {$daysUntilDue} day" . ($daysUntilDue > 1 ? 's' : '') . '.';
        } elseif ($daysUntilDue > -3) {
            return 'Invoice is due within 3 days.';
        } elseif ($daysUntilDue > -7) {
            return 'Invoice is due within a week.';
        }

        return 'Invoice payment is upcoming.';
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
    <x-header title="Invoice {{ $invoice->invoice_number }}" separator>
        <x-slot:subtitle>
            {{ $invoice->description ?: 'Invoice Details' }} • {{ $this->formatDate($invoice->invoice_date) }}
        </x-slot:subtitle>

        <x-slot:actions>
            @if($this->canPayInvoice())
                <x-button
                    label="Pay Now"
                    icon="o-credit-card"
                    wire:click="redirectToPayment"
                    class="btn-primary"
                />
            @endif
            <x-button
                label="Download PDF"
                icon="o-arrow-down-tray"
                wire:click="downloadInvoice"
                class="btn-ghost"
            />
            <x-button
                label="Back to Invoices"
                icon="o-arrow-left"
                wire:click="redirectToInvoices"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <!-- Invoice Status and Payment Info -->
    <div class="grid grid-cols-1 gap-6 mb-8 md:grid-cols-4">
        <x-card>
            <div class="p-6 text-center">
                <div class="mb-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $this->getStatusColor($this->getActualStatus()) }}">
                        {{ ucfirst(str_replace('_', ' ', $this->getActualStatus())) }}
                    </span>
                </div>
                <div class="text-lg font-bold text-gray-900">{{ $this->formatCurrency($invoice->amount) }}</div>
                <div class="text-sm text-gray-500">Total Amount</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="mb-2 text-lg font-bold text-green-600">{{ $this->formatCurrency($financialSummary['total_paid']) }}</div>
                <div class="text-sm text-gray-500">Paid</div>
                <div class="w-full h-2 mt-2 bg-gray-200 rounded-full">
                    <div class="h-2 transition-all duration-300 bg-green-600 rounded-full" style="width: {{ $financialSummary['payment_progress'] }}%"></div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="text-lg font-bold {{ $financialSummary['remaining_amount'] > 0 ? 'text-orange-600' : 'text-green-600' }} mb-2">
                    {{ $this->formatCurrency($financialSummary['remaining_amount']) }}
                </div>
                <div class="text-sm text-gray-500">Remaining</div>
            </div>
        </x-card>

        <x-card>
            <div class="p-6 text-center">
                <div class="mb-2 text-lg font-bold text-blue-600">{{ $this->formatDate($invoice->due_date) }}</div>
                <div class="text-sm text-gray-500">Due Date</div>
                @if($this->isInvoiceOverdue() && !$financialSummary['is_fully_paid'])
                    <div class="mt-1 text-xs text-red-600">{{ abs($this->getDaysUntilDue()) }} days overdue</div>
                @elseif(!$financialSummary['is_fully_paid'])
                    <div class="mt-1 text-xs text-gray-500">{{ abs($this->getDaysUntilDue()) }} days remaining</div>
                @endif
            </div>
        </x-card>
    </div>

    <!-- Payment Urgency Alert -->
    @if(!$financialSummary['is_fully_paid'])
        <div class="mb-6 p-4 {{ $this->isInvoiceOverdue() ? 'bg-red-50 border border-red-200' : ($this->getDaysUntilDue() > -7 ? 'bg-yellow-50 border border-yellow-200' : 'bg-blue-50 border border-blue-200') }} rounded-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    @if($this->isInvoiceOverdue())
                        <x-icon name="o-exclamation-triangle" class="w-6 h-6 mr-3 text-red-600" />
                        <div>
                            <h3 class="font-medium text-red-800">Payment Overdue</h3>
                            <p class="text-sm text-red-700">{{ $this->getUrgencyMessage() }}</p>
                        </div>
                    @elseif($this->getDaysUntilDue() > -7)
                        <x-icon name="o-clock" class="w-6 h-6 mr-3 text-yellow-600" />
                        <div>
                            <h3 class="font-medium text-yellow-800">Payment Due Soon</h3>
                            <p class="text-sm text-yellow-700">{{ $this->getUrgencyMessage() }}</p>
                        </div>
                    @else
                        <x-icon name="o-information-circle" class="w-6 h-6 mr-3 text-blue-600" />
                        <div>
                            <h3 class="font-medium text-blue-800">Payment Information</h3>
                            <p class="text-sm text-blue-700">{{ $this->getUrgencyMessage() }}</p>
                        </div>
                    @endif
                </div>
                @if($this->canPayInvoice())
                    <x-button
                        label="Pay Now"
                        icon="o-credit-card"
                        wire:click="redirectToPayment"
                        class="btn-sm {{ $this->isInvoiceOverdue() ? 'btn-error' : 'btn-primary' }}"
                    />
                @endif
            </div>
        </div>
    @elseif($financialSummary['is_fully_paid'])
        <div class="p-4 mb-6 border border-green-200 rounded-lg bg-green-50">
            <div class="flex items-center">
                <x-icon name="o-check-circle" class="w-6 h-6 mr-3 text-green-600" />
                <div>
                    <h3 class="font-medium text-green-800">Payment Complete</h3>
                    <p class="text-sm text-green-700">This invoice has been paid in full. Thank you!</p>
                </div>
            </div>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px space-x-8" aria-label="Tabs">
                <button
                    wire:click="setActiveTab('overview')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'overview' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-document-text" class="inline w-4 h-4 mr-1" />
                    Invoice Details
                </button>
                <button
                    wire:click="setActiveTab('items')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'items' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-list-bullet" class="inline w-4 h-4 mr-1" />
                    Items ({{ count($invoiceItems) }})
                </button>
                <button
                    wire:click="setActiveTab('payments')"
                    class="py-2 px-1 border-b-2 font-medium text-sm {{ $activeTab === 'payments' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}"
                >
                    <x-icon name="o-currency-dollar" class="inline w-4 h-4 mr-1" />
                    Payments ({{ count($payments) }})
                </button>
            </nav>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Overview Tab -->
        @if($activeTab === 'overview')
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left Column - Invoice Details -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Invoice Information -->
                    <x-card title="Invoice Information">
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Invoice Number</label>
                                <p class="font-mono text-sm text-gray-900">{{ $invoice->invoice_number }}</p>
                            </div>

                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Status</label>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getStatusColor($this->getActualStatus()) }}">
                                    {{ ucfirst(str_replace('_', ' ', $this->getActualStatus())) }}
                                </span>
                            </div>

                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Invoice Date</label>
                                <p class="text-sm text-gray-900">{{ $this->formatDate($invoice->invoice_date) }}</p>
                            </div>

                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Due Date</label>
                                <p class="text-sm text-gray-900">{{ $this->formatDate($invoice->due_date) }}</p>
                            </div>

                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Amount</label>
                                <p class="text-lg font-semibold text-gray-900">{{ $this->formatCurrency($invoice->amount) }}</p>
                            </div>

                            <div>
                                <label class="block mb-1 text-sm font-medium text-gray-700">Academic Year</label>
                                <p class="text-sm text-gray-900">{{ $invoice->academicYear->name ?? 'N/A' }}</p>
                            </div>

                            @if($invoice->curriculum)
                                <div class="md:col-span-2">
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Program</label>
                                    <p class="text-sm text-gray-900">{{ $invoice->curriculum->name }}</p>
                                </div>
                            @endif

                            @if($invoice->description)
                                <div class="md:col-span-2">
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Description</label>
                                    <p class="text-sm text-gray-900">{{ $invoice->description }}</p>
                                </div>
                            @endif

                            @if($invoice->notes)
                                <div class="md:col-span-2">
                                    <label class="block mb-1 text-sm font-medium text-gray-700">Notes</label>
                                    <p class="text-sm italic text-gray-600">{{ $invoice->notes }}</p>
                                </div>
                            @endif
                        </div>
                    </x-card>

                    <!-- Student Information -->
                    @if($invoice->student)
                        <x-card title="Student Information">
                            <div class="flex items-center mb-4 space-x-4">
                                <div class="avatar">
                                    <div class="w-16 h-16 rounded-full">
                                        <img src="https://ui-avatars.com/api/?name={{ urlencode($invoice->student->full_name) }}&color=7F9CF5&background=EBF4FF" alt="{{ $invoice->student->full_name }}" />
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold">{{ $invoice->student->full_name }}</h3>
                                    @if($invoice->student->email)
                                        <p class="text-sm text-gray-500">{{ $invoice->student->email }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                @if($invoice->student->phone)
                                    <div>
                                        <label class="block mb-1 text-sm font-medium text-gray-700">Phone</label>
                                        <p class="text-sm text-gray-900">{{ $invoice->student->phone }}</p>
                                    </div>
                                @endif
                                @if($invoice->student->date_of_birth)
                                    <div>
                                        <label class="block mb-1 text-sm font-medium text-gray-700">Date of Birth</label>
                                        <p class="text-sm text-gray-900">{{ $this->formatDate($invoice->student->date_of_birth) }}</p>
                                    </div>
                                @endif
                            </div>
                        </x-card>
                    @endif

                    <!-- Created By Information -->
                    @if($invoice->createdBy)
                        <x-card title="Invoice Created By">
                            <div class="flex items-center space-x-3">
                                <div class="avatar">
                                    <div class="w-10 h-10 rounded-full">
                                        <img src="https://ui-avatars.com/api/?name={{ urlencode($invoice->createdBy->name) }}&color=7F9CF5&background=EBF4FF" alt="{{ $invoice->createdBy->name }}" />
                                    </div>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">{{ $invoice->createdBy->name }}</div>
                                    <div class="text-sm text-gray-500">{{ $this->formatDateTime($invoice->created_at) }}</div>
                                </div>
                            </div>
                        </x-card>
                    @endif
                </div>

                <!-- Right Column - Summary and Actions -->
                <div class="space-y-6">
                    <!-- Payment Summary -->
                    <x-card title="Payment Summary">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Invoice Amount</span>
                                <span class="font-medium">{{ $this->formatCurrency($invoice->amount) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-gray-600">Amount Paid</span>
                                <span class="font-medium text-green-600">{{ $this->formatCurrency($financialSummary['total_paid']) }}</span>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t">
                                <span class="text-sm font-medium text-gray-900">Amount Due</span>
                                <span class="font-bold {{ $financialSummary['remaining_amount'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                                    {{ $this->formatCurrency($financialSummary['remaining_amount']) }}
                                </span>
                            </div>
                            <div class="pt-2">
                                <div class="flex items-center justify-between mb-2 text-sm text-gray-600">
                                    <span>Payment Progress</span>
                                    <span>{{ $financialSummary['payment_progress'] }}%</span>
                                </div>
                                <div class="w-full h-3 bg-gray-200 rounded-full">
                                    <div class="h-3 transition-all duration-300 bg-blue-600 rounded-full" style="width: {{ $financialSummary['payment_progress'] }}%"></div>
                                </div>
                            </div>
                        </div>
                    </x-card>

                    <!-- Quick Actions -->
                    <x-card title="Quick Actions">
                        <div class="space-y-3">
                            @if($this->canPayInvoice())
                                <x-button
                                    label="Make Payment"
                                    icon="o-credit-card"
                                    wire:click="redirectToPayment"
                                    class="w-full btn-primary"
                                />
                            @endif
                            <x-button
                                label="Download PDF"
                                icon="o-arrow-down-tray"
                                wire:click="downloadInvoice"
                                class="w-full btn-outline"
                            />
                            <x-button
                                label="View Program"
                                icon="o-academic-cap"
                                wire:click="redirectToEnrollment"
                                class="w-full btn-outline"
                            />
                            <x-button
                                label="All Invoices"
                                icon="o-document-text"
                                wire:click="redirectToInvoices"
                                class="w-full btn-outline"
                            />
                        </div>
                    </x-card>

                    <!-- Invoice Timeline -->
                    <x-card title="Timeline">
                        <div class="space-y-3">
                            <div class="flex items-center text-sm">
                                <x-icon name="o-plus-circle" class="w-4 h-4 mr-2 text-blue-500" />
                                <div>
                                    <div class="font-medium">Invoice Created</div>
                                    <div class="text-gray-500">{{ $this->formatDate($invoice->created_at) }}</div>
                                </div>
                            </div>

                            @if($invoice->sent_at)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-paper-airplane" class="w-4 h-4 mr-2 text-green-500" />
                                    <div>
                                        <div class="font-medium">Invoice Sent</div>
                                        <div class="text-gray-500">{{ $this->formatDate($invoice->sent_at) }}</div>
                                    </div>
                                </div>
                            @endif

                            @if($financialSummary['payment_count'] > 0)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-currency-dollar" class="w-4 h-4 mr-2 text-green-500" />
                                    <div>
                                        <div class="font-medium">Payment Received</div>
                                        <div class="text-gray-500">{{ $financialSummary['payment_count'] }} payment(s)</div>
                                    </div>
                                </div>
                            @endif

                            @if($invoice->paid_date)
                                <div class="flex items-center text-sm">
                                    <x-icon name="o-check-circle" class="w-4 h-4 mr-2 text-green-500" />
                                    <div>
                                        <div class="font-medium">Fully Paid</div>
                                        <div class="text-gray-500">{{ $this->formatDate($invoice->paid_date) }}</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-card>
                </div>
            </div>
        @endif

        <!-- Items Tab -->
        @if($activeTab === 'items')
            <x-card title="Invoice Items">
                @if(count($invoiceItems) > 0)
                    <div class="overflow-x-auto">
                        <table class="table w-full">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Description</th>
                                    <th class="text-right">Quantity</th>
                                    <th class="text-right">Unit Price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($invoiceItems as $item)
                                    <tr class="hover">
                                        <td>
                                            <div class="font-medium">{{ $item['name'] }}</div>
                                        </td>
                                        <td>
                                            <div class="text-sm text-gray-600">{{ $item['description'] ?? 'No description' }}</div>
                                        </td>
                                        <td class="text-right">{{ $item['quantity'] ?? 1 }}</td>
                                        <td class="text-right">{{ $this->formatCurrency($item['amount'] ?? 0) }}</td>
                                        <td class="font-medium text-right">{{ $this->formatCurrency($item['total_amount'] ?? $item['amount'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2">
                                    <td colspan="4" class="font-semibold text-right">Total Amount:</td>
                                    <td class="text-lg font-bold text-right">{{ $this->formatCurrency($invoice->amount) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="py-8 text-center">
                        <x-icon name="o-list-bullet" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                        <h3 class="mb-2 text-lg font-medium text-gray-900">No Items Available</h3>
                        <p class="text-gray-500">Invoice items details are not available.</p>
                    </div>
                @endif
            </x-card>
        @endif

        <!-- Payments Tab -->
        @if($activeTab === 'payments')
            <div class="space-y-6">
                <!-- Payment Summary -->
                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <x-card>
                        <div class="p-6 text-center">
                            <div class="text-2xl font-bold text-green-600">{{ count($payments) }}</div>
                            <div class="text-sm text-gray-500">Total Payments</div>
                        </div>
                    </x-card>

                    <x-card>
                        <div class="p-6 text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->formatCurrency($financialSummary['total_paid']) }}</div>
                            <div class="text-sm text-gray-500">Amount Paid</div>
                        </div>
                    </x-card>

                    <x-card>
                        <div class="p-6 text-center">
                            <div class="text-2xl font-bold {{ $financialSummary['remaining_amount'] > 0 ? 'text-orange-600' : 'text-green-600' }}">
                                {{ $this->formatCurrency($financialSummary['remaining_amount']) }}
                            </div>
                            <div class="text-sm text-gray-500">Remaining</div>
                        </div>
                    </x-card>
                </div>

                <!-- Payments List -->
                <x-card title="Payment History">
                    @if(count($payments) > 0)
                        <div class="space-y-4">
                            @foreach($payments as $payment)
                                <div class="p-4 transition-shadow border border-gray-200 rounded-lg hover:shadow-sm">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center">
                                            <x-icon name="{{ $this->getPaymentMethodIcon($payment['payment_method'] ?? 'online') }}" class="w-8 h-8 mr-3 text-blue-500" />
                                            <div>
                                                <div class="font-medium text-gray-900">{{ $this->formatCurrency($payment['amount']) }}</div>
                                                <div class="text-sm text-gray-500">{{ $this->formatDate($payment['payment_date']) }}</div>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $this->getPaymentStatusColor($payment['status']) }}">
                                            {{ ucfirst($payment['status']) }}
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                                        <div>
                                            <span class="text-gray-600">Method:</span>
                                            <span class="ml-1">{{ ucfirst(str_replace('_', ' ', $payment['payment_method'] ?? 'Online')) }}</span>
                                        </div>
                                        @if($payment['reference_number'])
                                            <div>
                                                <span class="text-gray-600">Reference:</span>
                                                <span class="ml-1 font-mono">{{ $payment['reference_number'] }}</span>
                                            </div>
                                        @endif
                                        @if($payment['notes'])
                                            <div>
                                                <span class="text-gray-600">Notes:</span>
                                                <span class="ml-1">{{ $payment['notes'] }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <x-icon name="o-currency-dollar" class="w-16 h-16 mx-auto mb-4 text-gray-300" />
                            <h3 class="mb-2 text-lg font-medium text-gray-900">No Payments Yet</h3>
                            <p class="mb-4 text-gray-500">No payments have been made for this invoice.</p>
                            @if($this->canPayInvoice())
                                <x-button
                                    label="Make First Payment"
                                    icon="o-credit-card"
                                    wire:click="redirectToPayment"
                                    class="btn-primary"
                                />
                            @endif
                        </div>
                    @endif
                </x-card>

                <!-- Recent Payment History (Other Invoices) -->
                @if(count($paymentHistory) > 0)
                    <x-card title="Recent Payment History">
                        <div class="mb-4 text-sm text-gray-600">Your recent payments across all invoices</div>
                        <div class="space-y-3">
                            @foreach($paymentHistory as $payment)
                                <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-b-0">
                                    <div class="flex items-center">
                                        <x-icon name="{{ $this->getPaymentMethodIcon($payment['payment_method'] ?? 'online') }}" class="w-5 h-5 mr-3 text-gray-400" />
                                        <div>
                                            <div class="text-sm font-medium">{{ $this->formatCurrency($payment['amount']) }}</div>
                                            <div class="text-xs text-gray-500">{{ $this->formatDate($payment['payment_date']) }}</div>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium {{ $this->getPaymentStatusColor($payment['status']) }}">
                                        {{ ucfirst($payment['status']) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif
            </div>
        @endif
    </div>

    <!-- Floating Payment Button -->
    @if($this->canPayInvoice())
        <div class="fixed z-50 bottom-6 right-6">
            <x-button
                icon="o-credit-card"
                wire:click="redirectToPayment"
                class="btn-circle btn-primary shadow-lg btn-lg {{ $this->isInvoiceOverdue() ? 'animate-pulse' : '' }}"
                title="Make Payment"
            />
        </div>
    @endif
</div>
