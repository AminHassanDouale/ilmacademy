<?php

use App\Models\Invoice;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        // Ensure parent can only view their own children's invoices
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
            $this->error('You do not have permission to view this invoice.');
            $this->redirect(route('parent.invoices.index'));
            return;
        }

        $this->invoice = $invoice->load(['student.user', 'academicYear', 'curriculum', 'items', 'payments']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'view',
            "Viewed invoice details: {$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            ['ip' => request()->ip()]
        );
    }

    #[Title('Invoice Details')]
    public function title(): string
    {
        return "Invoice: " . $this->invoice->invoice_number;
    }

    // Get total amount paid
    public function amountPaid()
    {
        return $this->invoice->amountPaid();
    }

    // Get remaining balance
    public function remainingBalance()
    {
        return $this->invoice->remainingBalance();
    }

    // Download invoice
    public function downloadInvoice()
    {
        $this->redirect(route('parent.invoices.download', $this->invoice->id));
    }

    // Pay invoice
    public function payInvoice()
    {
        if (!$this->invoice->isPaid()) {
            $this->redirect(route('parent.invoices.pay', $this->invoice->id));
        }
    }
};?>

<div>
    <!-- Page header -->
    <x-header :title="'Invoice: ' . $invoice->invoice_number" separator>
        <x-slot:actions>
            <x-button label="Back to Invoices" icon="o-arrow-left" link="{{ route('parent.invoices.index') }}" />

            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-primary">
                    <x-icon name="o-ellipsis-vertical" class="w-5 h-5 mr-2" />
                    Actions
                </label>
                <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
                    <li>
                        <a wire:click="downloadInvoice">
                            <x-icon name="o-arrow-down-tray" class="w-5 h-5" />
                            Download PDF
                        </a>
                    </li>
                    @if(!$invoice->isPaid())
                        <li>
                            <a wire:click="payInvoice">
                                <x-icon name="o-credit-card" class="w-5 h-5" />
                                Pay Now
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid gap-6 md:grid-cols-3">
        <!-- INVOICE DETAILS CARD -->
        <div class="md:col-span-2">
            <x-card>
                <!-- Invoice Header -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-2xl font-bold">Invoice</h2>
                        <p class="text-sm text-gray-500">{{ $invoice->invoice_number }}</p>
                    </div>
                    <div>
                        <x-badge
                            label="{{ ucfirst($invoice->status) }}"
                            size="lg"
                            color="{{ match($invoice->status) {
                                'paid' => 'success',
                                'pending' => 'warning',
                                'overdue' => 'error',
                                'cancelled' => 'ghost',
                                default => 'ghost'
                            } }}"
                        />
                    </div>
                </div>

                <!-- Invoice Details -->
                <div class="grid grid-cols-2 gap-6 mb-6">
                    <div>
                        <h3 class="mb-1 text-sm font-medium text-gray-500">Invoice Date</h3>
                        <p>{{ $invoice->invoice_date->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <h3 class="mb-1 text-sm font-medium text-gray-500">Due Date</h3>
                        <p class="{{ $invoice->isOverdue() ? 'text-error' : '' }}">
                            {{ $invoice->due_date->format('d/m/Y') }}
                            @if($invoice->isOverdue())
                                <span class="text-error">({{ $invoice->daysOverdue() }} days overdue)</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <h3 class="mb-1 text-sm font-medium text-gray-500">Academic Year</h3>
                        <p>{{ $invoice->academicYear->name ?? 'N/A' }}</p>
                    </div>
                    <div>
                        <h3 class="mb-1 text-sm font-medium text-gray-500">Curriculum</h3>
                        <p>{{ $invoice->curriculum->name ?? 'N/A' }}</p>
                    </div>
                </div>

                <!-- Student Information -->
                <div class="mb-6">
                    <h3 class="mb-2 font-medium">Student Information</h3>
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center gap-3">
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
                                <div class="text-sm opacity-70">ID: {{ $invoice->student->id }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invoice Items -->
                <div class="mb-6">
                    <h3 class="mb-2 font-medium">Invoice Items</h3>
                    <div class="overflow-x-auto">
                        <table class="table w-full">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th class="text-right">Quantity</th>
                                    <th class="text-right">Price</th>
                                    <th class="text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if($invoice->items->isEmpty())
                                    <tr>
                                        <td colspan="4" class="py-4 text-center">
                                            <p class="text-gray-500">{{ $invoice->description }}</p>
                                        </td>
                                    </tr>
                                @else
                                    @foreach($invoice->items as $item)
                                        <tr>
                                            <td>{{ $item->description }}</td>
                                            <td class="text-right">{{ $item->quantity }}</td>
                                            <td class="text-right">{{ number_format($item->price, 2) }}</td>
                                            <td class="text-right">{{ number_format($item->quantity * $item->price, 2) }}</td>
                                        </tr>
                                    @endforeach
                                @endif
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="font-medium text-right">Total</td>
                                    <td class="font-bold text-right">{{ number_format($invoice->amount, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Notes -->
                @if($invoice->notes)
                    <div class="mb-6">
                        <h3 class="mb-2 font-medium">Notes</h3>
                        <div class="p-4 rounded-lg bg-base-200">
                            {{ $invoice->notes }}
                        </div>
                    </div>
                @endif

                <!-- Payment Summary -->
                <div>
                    <h3 class="mb-2 font-medium">Payment Summary</h3>
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center justify-between mb-2">
                            <span>Invoice Total:</span>
                            <span class="font-medium">{{ number_format($invoice->amount, 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between mb-2">
                            <span>Amount Paid:</span>
                            <span class="font-medium text-success">{{ number_format($amountPaid(), 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span>Remaining Balance:</span>
                            <span class="font-bold {{ $remainingBalance() > 0 ? 'text-error' : 'text-success' }}">{{ number_format($remainingBalance(), 2) }}</span>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>

        <!-- PAYMENT HISTORY CARD -->
        <div class="md:col-span-1">
            <x-card title="Payment History" separator>
                @if($invoice->payments->isEmpty())
                    <div class="py-8 text-center">
                        <x-icon name="o-credit-card" class="w-16 h-16 mx-auto text-gray-400" />
                        <h3 class="mt-2 text-lg font-semibold text-gray-600">No payments yet</h3>
                        @if(!$invoice->isPaid())
                            <p class="mb-4 text-gray-500">No payments have been made for this invoice.</p>
                            <x-button
                                label="Pay Now"
                                icon="o-credit-card"
                                wire:click="payInvoice"
                                class="btn-primary"
                            />
                        @else
                            <p class="text-gray-500">This invoice is marked as paid, but no payment records are available.</p>
                        @endif
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($invoice->payments as $payment)
                            <div class="p-3 border rounded-lg">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <div class="font-medium">{{ number_format($payment->amount, 2) }}</div>
                                        <div class="text-sm text-gray-500">{{ $payment->payment_method }}</div>
                                    </div>
                                    <x-badge
                                        label="{{ ucfirst($payment->status) }}"
                                        color="{{ match($payment->status) {
                                            'completed' => 'success',
                                            'pending' => 'warning',
                                            'failed' => 'error',
                                            'refunded' => 'info',
                                            default => 'ghost'
                                        } }}"
                                    />
                                </div>
                                <div class="mt-2 text-sm text-gray-500">
                                    {{ $payment->created_at->format('d/m/Y H:i') }}
                                </div>
                                @if($payment->transaction_id)
                                    <div class="mt-1 text-xs">
                                        Ref: {{ $payment->transaction_id }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if(!$invoice->isPaid())
                        <div class="divider"></div>
                        <div class="text-center">
                            <x-button
                                label="Make Payment"
                                icon="o-credit-card"
                                wire:click="payInvoice"
                                class="btn-primary"
                            />
                        </div>
                    @endif
                @endif
            </x-card>
        </div>
    </div>
</div>
