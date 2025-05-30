<?php

use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Invoice Details')] class extends Component {
    use Toast;

    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice->load([
            'programEnrollment.childProfile',
            'programEnrollment.curriculum',
            'programEnrollment.academicYear',
            'programEnrollment.paymentPlan',
            'createdBy',
            'payments'
        ]);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed invoice #{$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            ['ip' => request()->ip()]
        );
    }

    public function markAsPaid(): void
    {
        if ($this->invoice->status === 'paid') {
            $this->warning('Invoice is already marked as paid.');
            return;
        }

        $this->invoice->update([
            'status' => 'paid',
            'paid_date' => now()
        ]);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'update',
            "Marked invoice #{$this->invoice->invoice_number} as paid",
            Invoice::class,
            $this->invoice->id
        );

        $this->success('Invoice marked as paid successfully.');
        $this->invoice->refresh();
    }

    public function markAsUnpaid(): void
    {
        if ($this->invoice->status === 'unpaid') {
            $this->warning('Invoice is already marked as unpaid.');
            return;
        }

        $this->invoice->update([
            'status' => 'unpaid',
            'paid_date' => null
        ]);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'update',
            "Marked invoice #{$this->invoice->invoice_number} as unpaid",
            Invoice::class,
            $this->invoice->id
        );

        $this->success('Invoice marked as unpaid successfully.');
        $this->invoice->refresh();
    }

    public function getFormattedAmountProperty(): string
    {
        return '$' . number_format($this->invoice->amount, 2);
    }

    public function getFormattedInvoiceDateProperty(): string
    {
        return $this->invoice->invoice_date ? $this->invoice->invoice_date->format('d/m/Y') : 'N/A';
    }

    public function getFormattedDueDateProperty(): string
    {
        return $this->invoice->due_date ? $this->invoice->due_date->format('d/m/Y') : 'N/A';
    }

    public function getFormattedPaidDateProperty(): string
    {
        return $this->invoice->paid_date ? $this->invoice->paid_date->format('d/m/Y') : 'N/A';
    }

    public function getStatusColorProperty(): string
    {
        return match(strtolower($this->invoice->status)) {
            'paid' => 'success',
            'unpaid' => 'warning',
            'overdue' => 'error',
            'cancelled' => 'error',
            'draft' => 'ghost',
            default => 'ghost'
        };
    }

    public function getIsOverdueProperty(): bool
    {
        return $this->invoice->due_date &&
               $this->invoice->due_date < now() &&
               strtolower($this->invoice->status) !== 'paid';
    }

    public function getDaysOverdueProperty(): int
    {
        if (!$this->isOverdue) {
            return 0;
        }

        return now()->diffInDays($this->invoice->due_date);
    }

    public function with(): array
    {
        return [];
    }
};?>
<div>
    <!-- Page header -->
    <x-header title="Invoice #{{ $invoice->invoice_number }}" separator>
        <x-slot:middle>
            <x-badge
                label="{{ ucfirst($invoice->status) }}"
                color="{{ $this->statusColor }}"
                class="badge-lg"
            />
            @if($this->isOverdue)
                <x-badge
                    label="Overdue ({{ $this->daysOverdue }} days)"
                    color="error"
                    class="ml-2 badge-lg"
                />
            @endif
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                @if(strtolower($invoice->status) !== 'paid')
                    <x-button
                        label="Mark as Paid"
                        icon="o-check-circle"
                        wire:click="markAsPaid"
                        color="success"
                        wire:confirm="Are you sure you want to mark this invoice as paid?"
                    />
                @else
                    <x-button
                        label="Mark as Unpaid"
                        icon="o-x-circle"
                        wire:click="markAsUnpaid"
                        color="warning"
                        wire:confirm="Are you sure you want to mark this invoice as unpaid?"
                    />
                @endif

                <x-button
                    label="Edit"
                    icon="o-pencil"
                    link="{{ route('admin.invoices.edit', $invoice->id) }}"
                    color="primary"
                />

                <x-button
                    label="Back to Invoices"
                    icon="o-arrow-left"
                    link="{{ route('admin.invoices.index') }}"
                    class="btn-ghost"
                />
            </div>
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
                        <div class="text-2xl font-bold text-green-600">{{ $this->formattedAmount }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Invoice Date</div>
                        <div class="font-semibold">{{ $this->formattedInvoiceDate }}</div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Due Date</div>
                        <div class="font-semibold {{ $this->isOverdue ? 'text-red-600' : '' }}">
                            {{ $this->formattedDueDate }}
                            @if($this->isOverdue)
                                <span class="text-xs text-red-500">(Overdue)</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div class="text-sm font-medium text-gray-500">Status</div>
                        <div>
                            <x-badge
                                label="{{ ucfirst($invoice->status) }}"
                                color="{{ $this->statusColor }}"
                                class="badge-sm"
                            />
                        </div>
                    </div>

                    @if($invoice->paid_date)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Paid Date</div>
                            <div class="font-semibold text-green-600">{{ $this->formattedPaidDate }}</div>
                        </div>
                    @endif

                    @if($invoice->payment_method)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Payment Method</div>
                            <div class="font-semibold">{{ $invoice->payment_method }}</div>
                        </div>
                    @endif

                    @if($invoice->reference)
                        <div>
                            <div class="text-sm font-medium text-gray-500">Reference</div>
                            <div class="font-semibold">{{ $invoice->reference }}</div>
                        </div>
                    @endif
                </div>

                @if($invoice->notes)
                    <div class="pt-6 mt-6 border-t border-gray-200">
                        <div class="mb-2 text-sm font-medium text-gray-500">Notes</div>
                        <div class="p-3 rounded-md bg-gray-50">
                            {{ $invoice->notes }}
                        </div>
                    </div>
                @endif
            </x-card>

            <!-- Student & Enrollment Information -->
            @if($invoice->programEnrollment)
                <x-card title="Student & Enrollment Information">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student Name</div>
                            <div class="text-lg font-semibold">
                                {{ $invoice->programEnrollment->childProfile ? $invoice->programEnrollment->childProfile->full_name : 'Unknown Student' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Enrollment ID</div>
                            <div class="font-semibold">#{{ $invoice->programEnrollment->id }}</div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Curriculum</div>
                            <div class="font-semibold">
                                {{ $invoice->programEnrollment->curriculum ? $invoice->programEnrollment->curriculum->name : 'Unknown Curriculum' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Academic Year</div>
                            <div class="flex items-center">
                                <span class="font-semibold">
                                    {{ $invoice->programEnrollment->academicYear ? $invoice->programEnrollment->academicYear->name : 'Unknown Academic Year' }}
                                </span>
                                @if($invoice->programEnrollment->academicYear && $invoice->programEnrollment->academicYear->is_current)
                                    <x-badge label="Current" color="success" class="ml-2 badge-xs" />
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Enrollment Status</div>
                            <div>
                                <x-badge
                                    label="{{ $invoice->programEnrollment->status }}"
                                    color="{{ match(strtolower($invoice->programEnrollment->status ?: '')) {
                                        'active' => 'success',
                                        'pending' => 'warning',
                                        'completed' => 'info',
                                        'cancelled' => 'error',
                                        default => 'ghost'
                                    } }}"
                                    class="badge-sm"
                                />
                            </div>
                        </div>

                        @if($invoice->programEnrollment->paymentPlan)
                            <div>
                                <div class="text-sm font-medium text-gray-500">Payment Plan</div>
                                <div class="font-semibold">
                                    {{ $invoice->programEnrollment->paymentPlan->name ?? ucfirst($invoice->programEnrollment->paymentPlan->type) }}
                                    <span class="text-sm text-gray-500">
                                        (${{ number_format($invoice->programEnrollment->paymentPlan->amount, 2) }})
                                    </span>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="pt-4 mt-4 border-t border-gray-200">
                        <x-button
                            label="View Enrollment Details"
                            icon="o-eye"
                            link="{{ route('admin.enrollments.show', $invoice->programEnrollment->id) }}"
                            color="ghost"
                            size="sm"
                        />
                    </div>
                </x-card>
            @endif
        </div>

        <!-- Right column (1/3) - Actions & Summary -->
        <div class="space-y-6">
            <!-- Quick Actions -->
            <x-card title="Quick Actions">
                <div class="space-y-3">
                    @if(strtolower($invoice->status) !== 'paid')
                        <x-button
                            label="Mark as Paid"
                            icon="o-check-circle"
                            wire:click="markAsPaid"
                            color="success"
                            class="w-full"
                            wire:confirm="Are you sure you want to mark this invoice as paid?"
                        />
                    @else
                        <x-button
                            label="Mark as Unpaid"
                            icon="o-x-circle"
                            wire:click="markAsUnpaid"
                            color="warning"
                            class="w-full"
                            wire:confirm="Are you sure you want to mark this invoice as unpaid?"
                        />
                    @endif

                    <x-button
                        label="Edit Invoice"
                        icon="o-pencil"
                        link="{{ route('admin.invoices.edit', $invoice->id) }}"
                        color="primary"
                        class="w-full"
                    />

                    <x-button
                        label="Print Invoice"
                        icon="o-printer"
                        onclick="window.print()"
                        color="ghost"
                        class="w-full"
                    />

                    <x-button
                        label="Download PDF"
                        icon="o-arrow-down-tray"
                        color="ghost"
                        class="w-full"
                    />
                </div>
            </x-card>

            <!-- Invoice Summary -->
            <x-card title="Invoice Summary">
                <div class="space-y-4">
                    <div class="flex items-center justify-between pb-2 border-b border-gray-200">
                        <span class="text-sm font-medium text-gray-500">Subtotal</span>
                        <span class="font-semibold">{{ $this->formattedAmount }}</span>
                    </div>

                    <div class="flex items-center justify-between pb-2 border-b border-gray-200">
                        <span class="text-sm font-medium text-gray-500">Tax</span>
                        <span class="font-semibold">$0.00</span>
                    </div>

                    <div class="flex items-center justify-between text-lg font-bold">
                        <span>Total</span>
                        <span class="text-green-600">{{ $this->formattedAmount }}</span>
                    </div>

                    @if($this->isOverdue)
                        <div class="p-3 mt-4 border border-red-200 rounded-md bg-red-50">
                            <div class="text-sm font-medium text-red-800">
                                ⚠️ This invoice is {{ $this->daysOverdue }} days overdue
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Creation Information -->
            <x-card title="Creation Information">
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="font-medium text-gray-500">Created by:</span>
                        <div class="font-semibold">
                            {{ $invoice->createdBy ? $invoice->createdBy->name : 'Unknown User' }}
                        </div>
                    </div>

                    <div>
                        <span class="font-medium text-gray-500">Created on:</span>
                        <div class="font-semibold">
                            {{ $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : 'Unknown' }}
                        </div>
                    </div>

                    @if($invoice->updated_at && $invoice->updated_at != $invoice->created_at)
                        <div>
                            <span class="font-medium text-gray-500">Last updated:</span>
                            <div class="font-semibold">
                                {{ $invoice->updated_at->format('d/m/Y H:i') }}
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Payment History (if you have payments) -->
            @if($invoice->payments && $invoice->payments->count() > 0)
                <x-card title="Payment History">
                    <div class="space-y-3">
                        @foreach($invoice->payments as $payment)
                            <div class="flex items-center justify-between p-3 rounded-md bg-gray-50">
                                <div>
                                    <div class="font-semibold">${{ number_format($payment->amount, 2) }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ $payment->payment_date ? $payment->payment_date->format('d/m/Y') : 'N/A' }}
                                    </div>
                                </div>
                                <x-badge
                                    label="{{ ucfirst($payment->status) }}"
                                    color="{{ $payment->status === 'completed' ? 'success' : 'warning' }}"
                                    class="badge-xs"
                                />
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    </div>
</div>

<style>
@media print {
    .no-print,
    .btn,
    button,
    [wire\:click] {
        display: none !important;
    }

    .card {
        break-inside: avoid;
    }
}
</style>
