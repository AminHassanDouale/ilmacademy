<?php

use App\Models\Invoice;
use App\Models\ProgramEnrollment;
use App\Models\PaymentPlan;
use App\Models\ActivityLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Invoice')] class extends Component {
    use Toast;

    // Form data
    public ?int $programEnrollmentId = null;
    public ?int $paymentPlanId = null;
    public string $amount = '';
    public ?string $dueDate = null;
    public string $status = Invoice::STATUS_PENDING; // Use model constant and set default
    public ?string $description = null; // Add description field
    public ?string $notes = null;
    public string $invoiceNumber = '';

    // Options - Use Invoice model constants
    protected array $validStatuses = [
        Invoice::STATUS_DRAFT,
        Invoice::STATUS_SENT,
        Invoice::STATUS_PENDING,
        Invoice::STATUS_PARTIALLY_PAID,
        Invoice::STATUS_PAID,
        Invoice::STATUS_OVERDUE,
        Invoice::STATUS_CANCELLED,
    ];

    // Mount the component
    public function mount(?int $programEnrollmentId = null): void
    {
        Log::info('Invoice Create Component Mounted', [
            'user_id' => Auth::id(),
            'program_enrollment_id' => $programEnrollmentId,
            'ip' => request()->ip()
        ]);

        // Set program enrollment ID if provided
        if ($programEnrollmentId) {
            $this->programEnrollmentId = $programEnrollmentId;
            $this->loadProgramEnrollmentData();
        }

        // Generate invoice number using model method
        $this->invoiceNumber = Invoice::getNextInvoiceNumber();

        // Set default due date to 30 days from now (stored in Y-m-d format)
        $this->dueDate = now()->addDays(30)->format('Y-m-d');

        Log::info('Invoice Component Initialized', [
            'invoice_number' => $this->invoiceNumber,
            'due_date' => $this->dueDate,
            'program_enrollment_id' => $this->programEnrollmentId,
            'status' => $this->status
        ]);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed create invoice page',
            Invoice::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Load data based on selected program enrollment
    public function loadProgramEnrollmentData(): void
    {
        Log::info('Loading Program Enrollment Data', [
            'program_enrollment_id' => $this->programEnrollmentId
        ]);

        if ($this->programEnrollmentId) {
            $enrollment = ProgramEnrollment::with(['paymentPlan', 'curriculum'])->find($this->programEnrollmentId);

            if ($enrollment) {
                Log::info('Program Enrollment Found', [
                    'enrollment_id' => $enrollment->id,
                    'payment_plan_id' => $enrollment->payment_plan_id,
                    'has_payment_plan' => $enrollment->paymentPlan ? true : false
                ]);

                if ($enrollment->paymentPlan) {
                    $this->paymentPlanId = $enrollment->paymentPlan->id;
                    $this->amount = (string) $enrollment->paymentPlan->amount;

                    Log::info('Payment Plan Data Loaded', [
                        'payment_plan_id' => $this->paymentPlanId,
                        'amount' => $this->amount,
                        'payment_plan_type' => $enrollment->paymentPlan->type
                    ]);
                }

                // Set description based on enrollment details
                if ($enrollment->curriculum) {
                    $this->description = "Invoice for " . $enrollment->curriculum->name;
                }
            } else {
                Log::error('Program Enrollment Not Found', [
                    'program_enrollment_id' => $this->programEnrollmentId
                ]);
            }
        }
    }

    // Updated program enrollment ID
    public function updatedProgramEnrollmentId(): void
    {
        Log::info('Program Enrollment ID Updated', [
            'new_program_enrollment_id' => $this->programEnrollmentId
        ]);

        // Reset first, then load new data
        $this->reset(['paymentPlanId', 'amount', 'description']);
        $this->loadProgramEnrollmentData();
    }

    // Updated payment plan ID
    public function updatedPaymentPlanId(): void
    {
        Log::info('Payment Plan ID Updated', [
            'payment_plan_id' => $this->paymentPlanId
        ]);

        if ($this->paymentPlanId) {
            $paymentPlan = PaymentPlan::find($this->paymentPlanId);

            if ($paymentPlan) {
                $this->amount = (string) $paymentPlan->amount;
                Log::info('Payment Plan Amount Updated', [
                    'payment_plan_id' => $this->paymentPlanId,
                    'amount' => $this->amount,
                    'payment_plan_type' => $paymentPlan->type
                ]);
            } else {
                Log::error('Payment Plan Not Found', [
                    'payment_plan_id' => $this->paymentPlanId
                ]);
            }
        } else {
            $this->amount = '';
            Log::info('Payment Plan Cleared', ['amount_reset' => true]);
        }
    }

    // Save the invoice
    public function save(): void
    {
        Log::info('Invoice Save Started', [
            'user_id' => Auth::id(),
            'form_data' => [
                'programEnrollmentId' => $this->programEnrollmentId,
                'paymentPlanId' => $this->paymentPlanId,
                'invoiceNumber' => $this->invoiceNumber,
                'amount' => $this->amount,
                'dueDate' => $this->dueDate,
                'status' => $this->status,
                'description' => $this->description,
                'notes' => $this->notes,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'programEnrollmentId' => 'required|exists:program_enrollments,id',
                'paymentPlanId' => 'nullable|exists:payment_plans,id',
                'invoiceNumber' => 'required|string|unique:invoices,invoice_number',
                'amount' => 'required|numeric|min:0',
                'dueDate' => 'required|date',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
                'description' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
            ], [
                'programEnrollmentId.required' => 'Please select a program enrollment.',
                'programEnrollmentId.exists' => 'The selected program enrollment is invalid.',
                'invoiceNumber.unique' => 'This invoice number already exists.',
                'amount.required' => 'Please enter an amount.',
                'amount.numeric' => 'Amount must be a valid number.',
                'amount.min' => 'Amount must be greater than or equal to 0.',
                'dueDate.required' => 'Please select a due date.',
                'dueDate.date' => 'Due date must be a valid date.',
                'status.required' => 'Please select a status.',
                'status.in' => 'The selected status is invalid.',
            ]);

            Log::info('Validation Passed', ['validated_data' => $validated]);

            // Get enrollment to extract related IDs
            $enrollment = ProgramEnrollment::findOrFail($validated['programEnrollmentId']);

            Log::info('Enrollment Data Retrieved', [
                'enrollment_id' => $enrollment->id,
                'child_profile_id' => $enrollment->child_profile_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'curriculum_id' => $enrollment->curriculum_id
            ]);

            // Prepare data for DB
            $invoiceData = [
                'program_enrollment_id' => $validated['programEnrollmentId'],
                'payment_plan_id' => $validated['paymentPlanId'],
                'invoice_number' => $validated['invoiceNumber'],
                'amount' => $validated['amount'],
                'invoice_date' => now(),
                'due_date' => $validated['dueDate'],
                'status' => $validated['status'],
                'description' => $validated['description'],
                'notes' => $validated['notes'],
                // Add related IDs from enrollment
                'child_profile_id' => $enrollment->child_profile_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'curriculum_id' => $enrollment->curriculum_id,
                'created_by' => Auth::id(),
            ];

            // Add paid_date if status is paid
            if ($validated['status'] === Invoice::STATUS_PAID) {
                $invoiceData['paid_date'] = now();
                Log::info('Added Paid Date', ['paid_date' => $invoiceData['paid_date']]);
            }

            Log::info('Prepared Invoice Data', ['invoice_data' => $invoiceData]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create invoice
            Log::debug('Creating Invoice Record');
            $invoice = Invoice::create($invoiceData);
            Log::info('Invoice Created Successfully', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number
            ]);

            $studentName = $enrollment->childProfile ? $enrollment->childProfile->full_name : 'Unknown';

            Log::info('Retrieved Student Information', [
                'enrollment_id' => $enrollment->id,
                'student_name' => $studentName,
                'child_profile_id' => $enrollment->child_profile_id
            ]);

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created invoice #{$validated['invoiceNumber']} for student: {$studentName}",
                Invoice::class,
                $invoice->id,
                [
                    'invoice_number' => $validated['invoiceNumber'],
                    'amount' => $validated['amount'],
                    'status' => $validated['status'],
                    'student_name' => $studentName
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Invoice #{$validated['invoiceNumber']} has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to invoice show page
            Log::info('Redirecting to Invoice Show Page', [
                'invoice_id' => $invoice->id,
                'route' => 'admin.invoices.show'
            ]);

            $this->redirect(route('admin.invoices.show', $invoice->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
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

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Invoice Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'form_data' => [
                    'programEnrollmentId' => $this->programEnrollmentId,
                    'paymentPlanId' => $this->paymentPlanId,
                    'invoiceNumber' => $this->invoiceNumber,
                    'amount' => $this->amount,
                    'dueDate' => $this->dueDate,
                    'status' => $this->status,
                    'description' => $this->description,
                    'notes' => $this->notes,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get program enrollments for dropdown
    public function getProgramEnrollmentOptionsProperty(): array
    {
        try {
            Log::debug('Loading Program Enrollment Options');

            $enrollments = ProgramEnrollment::with('childProfile', 'curriculum', 'academicYear')
                ->orderByDesc('id')
                ->get();

            Log::info('Program Enrollments Loaded', [
                'count' => $enrollments->count(),
                'enrollment_ids' => $enrollments->pluck('id')->toArray()
            ]);

            $options = [];
            foreach ($enrollments as $enrollment) {
                $studentName = $enrollment->childProfile ? $enrollment->childProfile->full_name : 'Unknown Student';
                $curriculumName = $enrollment->curriculum ? $enrollment->curriculum->name : 'Unknown Curriculum';
                $academicYear = $enrollment->academicYear ? $enrollment->academicYear->name : 'Unknown Year';

                $displayName = "#{$enrollment->id} - {$studentName} ({$curriculumName}) - {$academicYear}";
                $options[$enrollment->id] = $displayName;
            }

            Log::debug('Program Enrollment Options Prepared', [
                'options_count' => count($options),
                'sample_options' => array_slice($options, 0, 3, true)
            ]);

            return $options;

        } catch (\Exception $e) {
            Log::error('Failed to Load Program Enrollment Options', [
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    // Get payment plans for dropdown
    public function getPaymentPlanOptionsProperty(): array
    {
        try {
            Log::debug('Loading Payment Plan Options', [
                'program_enrollment_id' => $this->programEnrollmentId
            ]);

            if (!$this->programEnrollmentId) {
                $plans = PaymentPlan::active()->orderBy('type')->get();
                Log::debug('Loading All Active Payment Plans', ['count' => $plans->count()]);
            } else {
                // If a program enrollment is selected, prioritize the associated payment plan
                $enrollment = ProgramEnrollment::find($this->programEnrollmentId);

                if ($enrollment && $enrollment->payment_plan_id) {
                    Log::debug('Loading Payment Plans with Priority', [
                        'associated_plan_id' => $enrollment->payment_plan_id
                    ]);

                    // Get the associated payment plan first, then others
                    $associatedPlan = PaymentPlan::active()->where('id', $enrollment->payment_plan_id)->get();
                    $otherPlans = PaymentPlan::active()->where('id', '!=', $enrollment->payment_plan_id)->orderBy('type')->get();
                    $plans = $associatedPlan->merge($otherPlans);
                } else {
                    $plans = PaymentPlan::active()->orderBy('type')->get();
                    Log::debug('Loading All Plans (No Associated Plan)', ['count' => $plans->count()]);
                }
            }

            $options = [];
            foreach ($plans as $plan) {
                // Create a nice display string
                $displayText = $plan->name ?? ucfirst($plan->type);
                $displayText .= ' - $' . number_format($plan->amount, 2);

                // Add curriculum name if it exists
                if ($plan->curriculum) {
                    $displayText .= ' (' . $plan->curriculum->name . ')';
                }

                $options[$plan->id] = $displayText;
            }

            Log::info('Payment Plan Options Prepared', [
                'options_count' => count($options),
                'plan_ids' => array_keys($options)
            ]);

            return $options;

        } catch (\Exception $e) {
            Log::error('Failed to Load Payment Plan Options', [
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    // Get statuses for dropdown - Use Invoice model constants
    public function getStatusOptionsProperty(): array
    {
        return [
            Invoice::STATUS_DRAFT => 'Draft',
            Invoice::STATUS_SENT => 'Sent',
            Invoice::STATUS_PENDING => 'Pending',
            Invoice::STATUS_PARTIALLY_PAID => 'Partially Paid',
            Invoice::STATUS_PAID => 'Paid',
            Invoice::STATUS_OVERDUE => 'Overdue',
            Invoice::STATUS_CANCELLED => 'Cancelled',
        ];
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
            Log::error('Failed to Load Selected Enrollment', [
                'program_enrollment_id' => $this->programEnrollmentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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
    <x-header title="Create New Invoice" separator>
        <x-slot:actions>
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
                <form wire:submit="save" class="space-y-6">
                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Invoice Number -->
                        <div>
                            <x-input
                                label="Invoice Number"
                                wire:model="invoiceNumber"
                                placeholder="e.g., INV-202505-0001"
                                readonly
                                help-text="Auto-generated invoice number"
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
                            @error('programEnrollmentId')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
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
                            @error('paymentPlanId')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
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
                            @error('dueDate')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Status -->
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Status *</label>
                            <select
                                wire:model.live="status"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                required
                            >
                                @foreach($this->statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('status')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div>
                            <x-input
                                label="Description"
                                wire:model.live="description"
                                placeholder="Brief description of the invoice"
                            />
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Notes -->
                        <div class="md:col-span-2">
                            <x-textarea
                                label="Notes"
                                wire:model.live="notes"
                                placeholder="Additional notes about this invoice (optional)"
                                rows="4"
                            />
                            @error('notes')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end pt-4">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.invoices.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Create Invoice"
                            icon="o-paper-airplane"
                            type="submit"
                            color="primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
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

            <!-- Invoice Preview Card -->
            <x-card title="Invoice Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    <div class="mb-3 text-center">
                        <div class="text-2xl font-bold">INVOICE</div>
                        <div class="font-mono">{{ $invoiceNumber }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <div class="text-sm font-medium text-gray-500">Student</div>
                            <div class="font-semibold">
                                {{ $this->selectedEnrollment && $this->selectedEnrollment->childProfile
                                    ? $this->selectedEnrollment->childProfile->full_name
                                    : 'Student name will appear here' }}
                            </div>
                        </div>

                        <div>
                            <div class="text-sm font-medium text-gray-500">Due Date</div>
                            <div class="font-semibold">
                                {{ $this->formattedDueDate ?: 'Select due date' }}
                            </div>
                        </div>
                    </div>

                    @if($description)
                        <div class="mb-4">
                            <div class="text-sm font-medium text-gray-500">Description</div>
                            <div>{{ $description }}</div>
                        </div>
                    @endif

                    <div class="py-4 my-4 border-t border-b border-base-300">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold">Amount Due</div>
                            <div class="font-mono text-xl font-bold">${{ number_format((float)$amount ?: 0, 2) }}</div>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-gray-500">
                        <div><strong>Status:</strong>
                            {{ $this->statusOptions[$status] ?? ucfirst(str_replace('_', ' ', $status)) }}
                        </div>
                        @if($notes)
                            <div class="mt-2"><strong>Notes:</strong> {{ $notes }}</div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Invoice Number</div>
                        <p class="text-gray-600">Automatically generated in format INV-YYYYMM-XXXX. Each invoice gets a unique sequential number.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Program Enrollment</div>
                        <p class="text-gray-600">Select the student enrollment this invoice relates to. This will automatically populate related information.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Amount</div>
                        <p class="text-gray-600">Enter the total amount due for this invoice. This will be automatically filled if you select a payment plan.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Due Date</div>
                        <p class="text-gray-600">The date by which payment is expected. Input uses browser date picker (YYYY-MM-DD) but displays in DD/MM/YYYY format. Defaults to 30 days from today.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Status</div>
                        <p class="text-gray-600">Set to "Pending" by default. If set to "Paid", the payment date will be recorded as today.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Description</div>
                        <p class="text-gray-600">Brief description of what this invoice is for. Will be auto-populated based on the selected enrollment.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Notes</div>
                        <p class="text-gray-600">Optional field for any additional information about this invoice.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
