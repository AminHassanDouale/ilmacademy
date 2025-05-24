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
    public string $status = 'Unpaid';
    public ?string $paymentMethod = null;
    public ?string $reference = null;
    public ?string $notes = null;
    public string $invoiceNumber = '';

    // Options
    protected array $validStatuses = ['Paid', 'Unpaid', 'Overdue', 'Cancelled'];
    protected array $validPaymentMethods = ['Credit Card', 'Bank Transfer', 'Cash', 'Check', 'Other'];

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

        // Generate invoice number
        $this->generateInvoiceNumber();

        // Set default due date to 30 days from now (stored in Y-m-d format)
        $this->dueDate = now()->addDays(30)->format('Y-m-d');

        Log::info('Invoice Component Initialized', [
            'invoice_number' => $this->invoiceNumber,
            'due_date' => $this->dueDate,
            'program_enrollment_id' => $this->programEnrollmentId
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

    // Generate unique invoice number
    protected function generateInvoiceNumber(): void
    {
        $prefix = 'INV-';
        $year = date('Y');
        $month = date('m');

        Log::debug('Generating Invoice Number', [
            'prefix' => $prefix,
            'year' => $year,
            'month' => $month
        ]);

        // Get the latest invoice number with this prefix pattern
        $latestInvoice = Invoice::where('invoice_number', 'like', "{$prefix}{$year}{$month}%")
            ->orderBy('id', 'desc')
            ->first();

        if ($latestInvoice) {
            // Extract the sequence number and increment
            $sequence = (int)substr($latestInvoice->invoice_number, -4);
            $sequence++;
            Log::debug('Found Latest Invoice', [
                'latest_invoice_number' => $latestInvoice->invoice_number,
                'extracted_sequence' => $sequence - 1,
                'new_sequence' => $sequence
            ]);
        } else {
            // Start with sequence number 1
            $sequence = 1;
            Log::debug('No Previous Invoices Found', ['starting_sequence' => $sequence]);
        }

        // Format: INV-YYYYMM-XXXX (e.g., INV-202505-0001)
        $this->invoiceNumber = sprintf("%s%s%s-%04d", $prefix, $year, $month, $sequence);

        Log::info('Invoice Number Generated', [
            'invoice_number' => $this->invoiceNumber,
            'sequence' => $sequence
        ]);
    }

    // Load data based on selected program enrollment
    public function loadProgramEnrollmentData(): void
    {
        Log::info('Loading Program Enrollment Data', [
            'program_enrollment_id' => $this->programEnrollmentId
        ]);

        if ($this->programEnrollmentId) {
            $enrollment = ProgramEnrollment::with('paymentPlan')->find($this->programEnrollmentId);

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
                } else {
                    Log::warning('Program Enrollment Has No Payment Plan', [
                        'enrollment_id' => $enrollment->id
                    ]);
                }
            } else {
                Log::error('Program Enrollment Not Found', [
                    'program_enrollment_id' => $this->programEnrollmentId
                ]);
            }
        }
    }

    // Updated program enrollment ID - FIXED ORDER
    public function updatedProgramEnrollmentId(): void
    {
        Log::info('Program Enrollment ID Updated', [
            'old_program_enrollment_id' => $this->programEnrollmentId,
            'new_program_enrollment_id' => $this->programEnrollmentId
        ]);

        // Reset first, then load new data
        $this->reset(['paymentPlanId', 'amount']);
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
                'paymentMethod' => $this->paymentMethod,
                'reference' => $this->reference,
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
                'paymentMethod' => 'nullable|string|in:' . implode(',', $this->validPaymentMethods),
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
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

            // Prepare data for DB - FIXED TO INCLUDE ALL REQUIRED FIELDS
            $invoiceData = [
                'program_enrollment_id' => $validated['programEnrollmentId'],
                'payment_plan_id' => $validated['paymentPlanId'],
                'invoice_number' => $validated['invoiceNumber'],
                'amount' => $validated['amount'],
                'invoice_date' => now(), // ADD THIS - MISSING FIELD!
                'due_date' => $validated['dueDate'],
                'status' => strtolower($validated['status']), // Convert to lowercase to match constants
                'payment_method' => $validated['paymentMethod'],
                'reference' => $validated['reference'],
                'notes' => $validated['notes'],
                // Add related IDs from enrollment
                'child_profile_id' => $enrollment->child_profile_id,
                'academic_year_id' => $enrollment->academic_year_id,
                'curriculum_id' => $enrollment->curriculum_id,
                'created_by' => Auth::id(),
            ];

            // Add paid_date if status is Paid
            if (strtolower($validated['status']) === 'paid') {
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
                    'paymentMethod' => $this->paymentMethod,
                    'reference' => $this->reference,
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
            <!-- Debug Info (remove in production) -->
            <x-card title="Debug Info" class="border-orange-200 bg-orange-50">
                <div class="space-y-2 text-xs">
                    <div><strong>Program Enrollments:</strong> {{ count($this->programEnrollmentOptions) }} available</div>
                    <div><strong>Payment Plans:</strong> {{ count($this->paymentPlanOptions) }} available</div>
                    <div><strong>Selected Enrollment ID:</strong> {{ $programEnrollmentId ?? 'None' }}</div>
                    <div><strong>Selected Payment Plan ID:</strong> {{ $paymentPlanId ?? 'None' }}</div>
                    <div><strong>Amount:</strong> ${{ $amount ?: '0.00' }}</div>
                    <div><strong>Due Date (Raw):</strong> {{ $dueDate ?? 'None' }}</div>
                    <div><strong>Due Date (Formatted):</strong> {{ $this->formattedDueDate ?: 'None' }}</div>

                    <!-- Show first few options for debugging -->
                    @if(count($this->programEnrollmentOptions) > 0)
                        <div class="mt-2">
                            <strong>Sample Enrollment Options:</strong>
                            @foreach(array_slice($this->programEnrollmentOptions, 0, 2, true) as $key => $value)
                                <div class="ml-2 text-xs truncate">{{ $key }}: {{ $value }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if(count($this->paymentPlanOptions) > 0)
                        <div class="mt-2">
                            <strong>Sample Payment Plan Options:</strong>
                            @foreach(array_slice($this->paymentPlanOptions, 0, 2, true) as $key => $value)
                                <div class="ml-2 text-xs truncate">{{ $key }}: {{ $value }}</div>
                            @endforeach
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

                    <div class="py-4 my-4 border-t border-b border-base-300">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold">Amount Due</div>
                            <div class="font-mono text-xl font-bold">${{ number_format((float)$amount ?: 0, 2) }}</div>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-gray-500">
                        <div><strong>Status:</strong> {{ $status }}</div>
                        @if($paymentMethod)
                            <div><strong>Payment Method:</strong> {{ $paymentMethod }}</div>
                        @endif
                        @if($reference)
                            <div><strong>Reference:</strong> {{ $reference }}</div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
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
                        <p class="text-gray-600">Set to "Unpaid" by default. If set to "Paid", the payment date will be recorded as today.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Reference</div>
                        <p class="text-gray-600">Optional field for tracking payment references like transaction IDs or check numbers.</p>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
