<?php

use App\Models\Invoice;
use App\Models\ProgramEnrollment;
use App\Models\PaymentPlan;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Invoice')] class extends Component {
    use Toast;

    public Invoice $invoice;

    // Form data
    public ?int $programEnrollmentId = null;
    public ?int $paymentPlanId = null;
    public string $amount = '';
    public ?string $dueDate = null;
    public string $status = 'Unpaid';
    public ?string $paymentMethod = null;
    public ?string $reference = null;
    public ?string $notes = null;
    public string $invoiceNumber = '';

    // Options
    protected array $validStatuses = ['Paid', 'Unpaid', 'Overdue', 'Cancelled'];
    protected array $validPaymentMethods = ['Credit Card', 'Bank Transfer', 'Cash', 'Check', 'Other'];

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice->load(['programEnrollment.paymentPlan']);

        // Pre-populate form with invoice data
        $this->programEnrollmentId = $this->invoice->program_enrollment_id;
        $this->paymentPlanId = $this->invoice->payment_plan_id;
        $this->invoiceNumber = $this->invoice->invoice_number;
        $this->amount = (string) $this->invoice->amount;
        $this->dueDate = $this->invoice->due_date ? $this->invoice->due_date->format('Y-m-d') : null;
        $this->status = ucfirst($this->invoice->status ?? 'Unpaid');
        $this->paymentMethod = $this->invoice->payment_method;
        $this->reference = $this->invoice->reference;
        $this->notes = $this->invoice->notes;

        Log::info('Invoice Edit Component Mounted', [
            'user_id' => Auth::id(),
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'ip' => request()->ip()
        ]);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for invoice #{$this->invoice->invoice_number}",
            Invoice::class,
            $this->invoice->id,
            ['ip' => request()->ip()]
        );
    }

    // Load data based on selected program enrollment
    public function loadProgramEnrollmentData(): void
    {
        Log::info('Loading Program Enrollment Data for Edit', [
            'program_enrollment_id' => $this->programEnrollmentId
        ]);

        if ($this->programEnrollmentId) {
            $enrollment = ProgramEnrollment::with('paymentPlan')->find($this->programEnrollmentId);

            if ($enrollment && $enrollment->paymentPlan) {
                $this->paymentPlanId = $enrollment->paymentPlan->id;
                $this->amount = (string) $enrollment->paymentPlan->amount;

                Log::info('Payment Plan Data Loaded for Edit', [
                    'payment_plan_id' => $this->paymentPlanId,
                    'amount' => $this->amount,
                    'payment_plan_type' => $enrollment->paymentPlan->type
                ]);
            }
        }
    }

    // Updated program enrollment ID
    public function updatedProgramEnrollmentId(): void
    {
        Log::info('Program Enrollment ID Updated in Edit', [
            'old_program_enrollment_id' => $this->programEnrollmentId,
            'new_program_enrollment_id' => $this->programEnrollmentId
        ]);

        // Only reset if different from original enrollment
        if ($this->programEnrollmentId !== $this->invoice->program_enrollment_id) {
            $this->reset(['paymentPlanId', 'amount']);
            $this->loadProgramEnrollmentData();
        }
    }

    // Updated payment plan ID
    public function updatedPaymentPlanId(): void
    {
        Log::info('Payment Plan ID Updated in Edit', [
            'payment_plan_id' => $this->paymentPlanId
        ]);

        if ($this->paymentPlanId) {
            $paymentPlan = PaymentPlan::find($this->paymentPlanId);

            if ($paymentPlan) {
                $this->amount = (string) $paymentPlan->amount;
                Log::info('Payment Plan Amount Updated in Edit', [
                    'payment_plan_id' => $this->paymentPlanId,
                    'amount' => $this->amount,
                    'payment_plan_type' => $paymentPlan->type
                ]);
            }
        }
    }

    // Update the invoice
    public function update(): void
    {
        Log::info('Invoice Update Started', [
            'user_id' => Auth::id(),
            'invoice_id' => $this->invoice->id,
            'form_data' => [
                'programEnrollmentId' => $this->programEnrollmentId,
                'paymentPlanId' => $this->paymentPlanId,
                'invoiceNumber' => $this->invoiceNumber,
                'amount' => $this->amount,
                'dueDate' => $this->dueDate,
                'status' => $this->status,
                'paymentMethod' => $this->paymentMethod,
                'reference' => $this->reference,
                'notes' => $this->notes,
            ]
        ]);

        try {
            // Validate form data
            $validated = $this->validate([
                'programEnrollmentId' => 'required|exists:program_enrollments,id',
                'paymentPlanId' => 'nullable|exists:payment_plans,id',
                'invoiceNumber' => 'required|string|unique:invoices,invoice_number,' . $this->invoice->id,
                'amount' => 'required|numeric|min:0',
                'dueDate' => 'required|date',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
                'paymentMethod' => 'nullable|string|in:' . implode(',', $this->validPaymentMethods),
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ]);

            Log::info('Invoice Update Validation Passed', ['validated_data' => $validated]);

            // Get enrollment to extract related IDs (in case enrollment changed)
            $enrollment = ProgramEnrollment::findOrFail($validated['programEnrollmentId']);

            // Prepare data for update
            $updateData = [
                'program_enrollment_id' => $validated['programEnrollmentId'],
                'payment_plan_id' => $validated['paymentPlanId'],
                'invoice_number' => $validated['invoiceNumber'],
                'amount' => $validated['amount'],
                'due_date' => $validated['dueDate'],
                'status' => strtolower($validated['status']),
                'payment_method' => $validated['paymentMethod'],
                'reference' => $validated['reference'],
                'notes' => $validated['notes'],
                // Update related IDs in case enrollment changed
                'child_profile_id' => $enrollment->child_profile_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'curriculum_id' => $enrollment->curriculum_id,
            ];

            // Handle paid_date logic
            $currentStatus = strtolower($this->invoice->status ?? '');
            $newStatus = strtolower($validated['status']);

            if ($newStatus === 'paid' && $currentStatus !== 'paid') {
                // Status changed to paid - set paid date
                $updateData['paid_date'] = now();
                Log::info('Invoice Status Changed to Paid - Setting Paid Date');
            } elseif ($newStatus !== 'paid' && $currentStatus === 'paid') {
                // Status changed from paid - clear paid date
                $updateData['paid_date'] = null;
                Log::info('Invoice Status Changed from Paid - Clearing Paid Date');
            }

            Log::info('Prepared Invoice Update Data', ['update_data' => $updateData]);

            DB::beginTransaction();

            // Store original data for comparison
            $originalData = $this->invoice->toArray();

            // Update invoice
            $this->invoice->update($updateData);

            Log::info('Invoice Updated Successfully', [
                'invoice_id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number
            ]);

            $studentName = $enrollment->childProfile ? $enrollment->childProfile->full_name : 'Unknown';

            // Log activity with changes
            $changes = [];
            foreach ($updateData as $key => $newValue) {
                $oldValue = $originalData[$key] ?? null;
                if ($oldValue != $newValue) {
                    $changes[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }

            ActivityLog::log(
                Auth::id(),
                'update',
                "Updated invoice #{$validated['invoiceNumber']} for student: {$studentName}",
                Invoice::class,
                $this->invoice->id,
                [
                    'invoice_number' => $validated['invoiceNumber'],
                    'student_name' => $studentName,
                    'changes' => $changes
                ]
            );

            DB::commit();

            $this->success("Invoice #{$validated['invoiceNumber']} has been successfully updated.");

            // Redirect to invoice show page
            $this->redirect(route('admin.invoices.show', $this->invoice->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Invoice Update Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'programEnrollmentId' => $this->programEnrollmentId,
                    'paymentPlanId' => $this->paymentPlanId,
                    'invoiceNumber' => $this->invoiceNumber,
                    'amount' => $this->amount,
                    'dueDate' => $this->dueDate,
                    'status' => $this->status,
                ]
            ]);

            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Update Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'invoice_id' => $this->invoice->id,
                'form_data' => [
                    'programEnrollmentId' => $this->programEnrollmentId,
                    'paymentPlanId' => $this->paymentPlanId,
                    'invoiceNumber' => $this->invoiceNumber,
                    'amount' => $this->amount,
                    'dueDate' => $this->dueDate,
                    'status' => $this->status,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get program enrollments for dropdown
    public function getProgramEnrollmentOptionsProperty(): array
    {
        try {
            $enrollments = ProgramEnrollment::with('childProfile', 'curriculum', 'academicYear')
                ->orderByDesc('id')
                ->get();

            $options = [];
            foreach ($enrollments as $enrollment) {
                $studentName = $enrollment->childProfile ? $enrollment->childProfile->full_name : 'Unknown Student';
                $curriculumName = $enrollment->curriculum ? $enrollment->curriculum->name : 'Unknown Curriculum';
                $academicYear = $enrollment->academicYear ? $enrollment->academicYear->name : 'Unknown Year';

                $displayName = "#{$enrollment->id} - {$studentName} ({$curriculumName}) - {$academicYear}";
                $options[$enrollment->id] = $displayName;
            }

            return $options;

        } catch (\Exception $e) {
            Log::error('Failed to Load Program Enrollment Options for Edit', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    // Get payment plans for dropdown
    public function getPaymentPlanOptionsProperty(): array
    {
        try {
            if (!$this->programEnrollmentId) {
                $plans = PaymentPlan::active()->orderBy('type')->get();
            } else {
                $enrollment = ProgramEnrollment::find($this->programEnrollmentId);

                if ($enrollment && $enrollment->payment_plan_id) {
                    $associatedPlan = PaymentPlan::active()->where('id', $enrollment->payment_plan_id)->get();
                    $otherPlans = PaymentPlan::active()->where('id', '!=', $enrollment->payment_plan_id)->orderBy('type')->get();
                    $plans = $associatedPlan->merge($otherPlans);
                } else {
                    $plans = PaymentPlan::active()->orderBy('type')->get();
                }
            }

            $options = [];
            foreach ($plans as $plan) {
                $displayText = $plan->name ?? ucfirst($plan->type);
                $displayText .= ' - $' . number_format($plan->amount, 2);

                if ($plan->curriculum) {
                    $displayText .= ' (' . $plan->curriculum->name . ')';
                }

                $options[$plan->id] = $displayText;
            }

            return $options;

        } catch (\Exception $e) {
            Log::error('Failed to Load Payment Plan Options for Edit', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    // Get statuses for dropdown
    public function getStatusOptionsProperty(): array
    {
        return array_combine($this->validStatuses, $this->validStatuses);
    }

    // Get payment methods for dropdown
    public function getPaymentMethodOptionsProperty(): array
    {
        return array_combine($this->validPaymentMethods, $this->validPaymentMethods);
    }

    // Format a date to d/m/Y format
    public function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        return date('d/m/Y', strtotime($date));
    }

    // Get formatted due date for display
    public function getFormattedDueDateProperty(): string
    {
        return $this->formatDate($this->dueDate);
    }

    // Get selected enrollment details
    public function getSelectedEnrollmentProperty()
    {
        if (!$this->programEnrollmentId) {
            return null;
        }

        try {
            return ProgramEnrollment::with('childProfile', 'curriculum', 'academicYear')
                ->find($this->programEnrollmentId);
        } catch (\Exception $e) {
            Log::error('Failed to Load Selected Enrollment for Edit', [
                'program_enrollment_id' => $this->programEnrollmentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function with(): array
    {
        return [];
    }
};

?>

<div>
    <!-- Page header -->
    <x-header title="Edit Invoice #{{ $invoice->invoice_number }}" separator>
        <x-slot:middle>
            <x-badge
                label="{{ ucfirst($invoice->status) }}"
                color="{{ match(strtolower($invoice->status)) {
                    'paid' => 'success',
                    'unpaid' => 'warning',
                    'overdue' => 'error',
                    'cancelled' => 'error',
                    default => 'ghost'
                } }}"
                class="badge-sm"
            />
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="View Invoice"
                icon="o-eye"
                link="{{ route('admin.invoices.show', $invoice->id) }}"
                class="btn-ghost"
            />
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('admin.invoices.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Invoice Information">
                <form wire:submit="update" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Invoice Number -->
                        <div>
                            <x-input
                                label="Invoice Number"
                                wire:model="invoiceNumber"
                                placeholder="e.g., INV-202505-0001"
                                required
                                help-text="Invoice number (must be unique)"
                            />
                        </div>

                        <!-- Amount -->
                        <div>
                            <x-input
                                label="Amount ($)"
                                wire:model.live="amount"
                                placeholder="0.00"
                                type="number"
                                step="0.01"
                                min="0"
                                required
                            />
                        </div>

                        <!-- Program Enrollment -->
                        <div class="md:col-span-2">
                            <label class="block mb-2 text-sm font-medium text-gray-700">Program Enrollment *</label>
                            <select
                                wire:model.live="programEnrollmentId"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select a program enrollment</option>
                                @foreach($this->programEnrollmentOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Payment Plan -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Payment Plan</label>
                            <select
                                wire:model.live="paymentPlanId"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="">Select a payment plan (optional)</option>
                                @foreach($this->paymentPlanOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Due Date -->
                        <div>
                            <x-input
                                label="Due Date"
                                wire:model.live="dueDate"
                                type="date"
                                required
                                help-text="Display format: {{ $this->formattedDueDate ?: 'No date selected' }}"
                            />
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Status *</label>
                            <select
                                wire:model.live="status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                <option value="">Select status</option>
                                @foreach($this->statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @if(strtolower($status) === 'paid')
                                <div class="mt-1 text-sm text-green-600">
                                    ℹ️ Paid date will be updated automatically
                                </div>
                            @endif
                        </div>

                        <!-- Payment Method -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Payment Method</label>
                            <select
                                wire:model.live="paymentMethod"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="">Select payment method (optional)</option>
                                @foreach($this->paymentMethodOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Reference -->
                        <div class="md:col-span-2">
                            <x-input
                                label="Reference"
                                wire:model.live="reference"
                                placeholder="e.g., Transaction ID, Check Number (optional)"
                            />
                        </div>

                        <!-- Notes -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Notes"
                                wire:model.live="notes"
                                placeholder="Additional notes about this invoice (optional)"
                                rows="4"
                            />
                        </div>
                    </div>

                    <div class="flex justify-end pt-4 space-x-2">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.invoices.show', $invoice->id) }}"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Update Invoice"
                            icon="o-check"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Info & Preview -->
        <div class="space-y-6">
            <!-- Current Invoice Info -->
            <x-card title="Current Invoice" class="border-blue-200 bg-blue-50">
                <div class="space-y-3 text-sm">
                    <div>
                        <span class="font-medium text-gray-600">Original Number:</span>
                        <div class="font-mono font-semibold">{{ $invoice->invoice_number }}</div>
                    </div>

                    <div>
                        <span class="font-medium text-gray-600">Original Amount:</span>
                        <div class="font-semibold">${{ number_format($invoice->amount, 2) }}</div>
                    </div>

                    <div>
                        <span class="font-medium text-gray-600">Current Status:</span>
                        <div>
                            <x-badge
                                label="{{ ucfirst($invoice->status) }}"
                                color="{{ match(strtolower($invoice->status)) {
                                    'paid' => 'success',
                                    'unpaid' => 'warning',
                                    'overdue' => 'error',
                                    'cancelled' => 'error',
                                    default => 'ghost'
                                } }}"
                                class="badge-xs"
                            />
                        </div>
                    </div>

                    <div>
                        <span class="font-medium text-gray-600">Created:</span>
                        <div class="font-semibold">
                            {{ $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : 'Unknown' }}
                        </div>
                    </div>

                    @if($invoice->paid_date)
                        <div>
                            <span class="font-medium text-gray-600">Paid Date:</span>
                            <div class="font-semibold text-green-600">
                                {{ $invoice->paid_date->format('d/m/Y H:i') }}
                            </div>
                        </div>
                    @endif
                </div>
            </x-card>

            <!-- Student Info Card (visible when enrollment selected) -->
            @if($this->selectedEnrollment)
                <x-card title="Student Information">
                    <div class="space-y-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student</div>
                            <div class="font-semibold">
                                {{ $this->selectedEnrollment->childProfile ? $this->selectedEnrollment->childProfile->full_name : 'Unknown student' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Curriculum</div>
                            <div>
                                {{ $this->selectedEnrollment->curriculum ? $this->selectedEnrollment->curriculum->name : 'Unknown curriculum' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Academic Year</div>
                            <div class="flex items-center">
                                {{ $this->selectedEnrollment->academicYear ? $this->selectedEnrollment->academicYear->name : 'Unknown academic year' }}
                                @if ($this->selectedEnrollment->academicYear && $this->selectedEnrollment->academicYear->is_current)
                                    <x-badge label="Current" color="success" class="ml-2 badge-xs" />
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Enrollment Status</div>
                            <div>
                                <x-badge
                                    label="{{ $this->selectedEnrollment->status }}"
                                    color="{{ match(strtolower($this->selectedEnrollment->status ?: '')) {
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
                    </div>

                    <div class="mt-4">
                        <x-button
                            label="View Enrollment"
                            icon="o-eye"
                            link="{{ route('admin.enrollments.show', $this->selectedEnrollment->id) }}"
                            color="ghost"
                            class="w-full"
                            size="sm"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Updated Invoice Preview -->
            <x-card title="Updated Invoice Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="mb-3 text-center">
                        <div class="text-2xl font-bold">INVOICE</div>
                        <div class="font-mono">{{ $invoiceNumber ?: $invoice->invoice_number }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student</div>
                            <div class="font-semibold">
                                {{ $this->selectedEnrollment && $this->selectedEnrollment->childProfile
                                    ? $this->selectedEnrollment->childProfile->full_name
                                    : ($invoice->programEnrollment && $invoice->programEnrollment->childProfile
                                        ? $invoice->programEnrollment->childProfile->full_name
                                        : 'Student name will appear here') }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Due Date</div>
                            <div class="font-semibold">
                                {{ $this->formattedDueDate ?: ($invoice->due_date ? $invoice->due_date->format('d/m/Y') : 'Select due date') }}
                            </div>
                        </div>
                    </div>

                    <div class="py-4 my-4 border-t border-b border-base-300">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold">Amount Due</div>
                            <div class="font-mono text-xl font-bold">${{ number_format((float)($amount ?: $invoice->amount), 2) }}</div>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-gray-500">
                        <div><strong>Status:</strong> {{ $status ?: ucfirst($invoice->status) }}</div>
                        @if($paymentMethod ?: $invoice->payment_method)
                            <div><strong>Payment Method:</strong> {{ $paymentMethod ?: $invoice->payment_method }}</div>
                        @endif
                        @if($reference ?: $invoice->reference)
                            <div><strong>Reference:</strong> {{ $reference ?: $invoice->reference }}</div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Status Changes</div>
                        <p class="text-gray-600">Changing status to "Paid" will automatically set the paid date. Changing from "Paid" will clear the paid date.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Amount Updates</div>
                        <p class="text-gray-600">The amount will be automatically updated if you select a different payment plan.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Invoice Number</div>
                        <p class="text-gray-600">Invoice number must be unique. The system will validate this when you save.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Data Integrity</div>
                        <p class="text-gray-600">Changing the program enrollment will update related student, curriculum, and academic year information.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
