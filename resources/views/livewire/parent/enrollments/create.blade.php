<?php

use App\Models\ProgramEnrollment;
use App\Models\ChildProfile;
use App\Models\Curriculum;
use App\Models\AcademicYear;
use App\Models\PaymentPlan;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Enrollment')] class extends Component {
    use Toast;

    // Form data
    public string $child_profile_id = '';
    public string $curriculum_id = '';
    public string $academic_year_id = '';
    public string $payment_plan_id = '';
    public string $status = 'Active';

    // Options
    public array $childOptions = [];
    public array $curriculumOptions = [];
    public array $academicYearOptions = [];
    public array $paymentPlanOptions = [];
    protected array $validStatuses = ['Active', 'Inactive'];

    // Selected models for preview
    public $selectedChild = null;
    public $selectedCurriculum = null;
    public $selectedAcademicYear = null;
    public $selectedPaymentPlan = null;

    public function mount(): void
    {
        // Pre-select child if provided in query
        if (request()->has('child')) {
            $childId = request()->get('child');
            $child = ChildProfile::where('id', $childId)
                ->where('parent_id', Auth::id())
                ->first();

            if ($child) {
                $this->child_profile_id = (string) $child->id;
                $this->loadSelectedChild();
            }
        }

        Log::info('Parent Enrollment Create Component Mounted', [
            'parent_id' => Auth::id(),
            'pre_selected_child' => $this->child_profile_id,
            'ip' => request()->ip()
        ]);

        $this->loadOptions();

        // Log activity
        ActivityLog::logActivity(
            Auth::id(),
            'access',
            'Accessed create enrollment page'
        );
    }

    protected function loadOptions(): void
    {
        // Load children options - only children of the authenticated parent
        try {
            $children = ChildProfile::where('parent_id', Auth::id())
                ->orderBy('first_name')
                ->get();

            $this->childOptions = $children->map(fn($child) => [
                'id' => $child->id,
                'name' => $child->full_name,
                'age' => $child->age,
            ])->toArray();
        } catch (\Exception $e) {
            $this->childOptions = [];
        }

        // Load curriculum options
        try {
            $curricula = Curriculum::orderBy('name')->get();
            $this->curriculumOptions = $curricula->map(fn($curriculum) => [
                'id' => $curriculum->id,
                'name' => $curriculum->name,
                'code' => $curriculum->code,
                'description' => $curriculum->description,
            ])->toArray();
        } catch (\Exception $e) {
            $this->curriculumOptions = [];
        }

        // Load academic year options
        try {
            $academicYears = AcademicYear::orderBy('name')->get();
            $this->academicYearOptions = $academicYears->map(fn($year) => [
                'id' => $year->id,
                'name' => $year->name,
            ])->toArray();
        } catch (\Exception $e) {
            $this->academicYearOptions = [];
        }

        // Load payment plan options based on selected curriculum
        $this->loadPaymentPlanOptions();
    }

    protected function loadPaymentPlanOptions(): void
    {
        try {
            if ($this->curriculum_id) {
                $paymentPlans = PaymentPlan::where('curriculum_id', $this->curriculum_id)
                    ->orderBy('name')
                    ->get();
            } else {
                $paymentPlans = PaymentPlan::orderBy('name')->get();
            }

            $this->paymentPlanOptions = $paymentPlans->map(fn($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'amount' => $plan->amount ?? 0,
                'description' => $plan->description ?? '',
            ])->toArray();
        } catch (\Exception $e) {
            $this->paymentPlanOptions = [];
        }
    }

    // Load selected models when form fields change
    public function updatedChildProfileId(): void
    {
        $this->loadSelectedChild();
    }

    public function updatedCurriculumId(): void
    {
        $this->loadSelectedCurriculum();
        $this->loadPaymentPlanOptions();
        $this->payment_plan_id = ''; // Reset payment plan when curriculum changes
        $this->selectedPaymentPlan = null;
    }

    public function updatedAcademicYearId(): void
    {
        $this->loadSelectedAcademicYear();
    }

    public function updatedPaymentPlanId(): void
    {
        $this->loadSelectedPaymentPlan();
    }

    protected function loadSelectedChild(): void
    {
        if ($this->child_profile_id) {
            $this->selectedChild = ChildProfile::where('id', $this->child_profile_id)
                ->where('parent_id', Auth::id())
                ->first();
        } else {
            $this->selectedChild = null;
        }
    }

    protected function loadSelectedCurriculum(): void
    {
        if ($this->curriculum_id) {
            $this->selectedCurriculum = Curriculum::find($this->curriculum_id);
        } else {
            $this->selectedCurriculum = null;
        }
    }

    protected function loadSelectedAcademicYear(): void
    {
        if ($this->academic_year_id) {
            $this->selectedAcademicYear = AcademicYear::find($this->academic_year_id);
        } else {
            $this->selectedAcademicYear = null;
        }
    }

    protected function loadSelectedPaymentPlan(): void
    {
        if ($this->payment_plan_id) {
            $this->selectedPaymentPlan = PaymentPlan::find($this->payment_plan_id);
        } else {
            $this->selectedPaymentPlan = null;
        }
    }

    // Save the enrollment
    public function save(): void
    {
        Log::info('Enrollment Creation Started', [
            'parent_id' => Auth::id(),
            'form_data' => [
                'child_profile_id' => $this->child_profile_id,
                'curriculum_id' => $this->curriculum_id,
                'academic_year_id' => $this->academic_year_id,
                'payment_plan_id' => $this->payment_plan_id,
                'status' => $this->status,
            ]
        ]);

        try {
            // Validate form data
            Log::debug('Starting Validation');

            $validated = $this->validate([
                'child_profile_id' => 'required|exists:child_profiles,id',
                'curriculum_id' => 'required|exists:curricula,id',
                'academic_year_id' => 'required|exists:academic_years,id',
                'payment_plan_id' => 'nullable|exists:payment_plans,id',
                'status' => 'required|string|in:' . implode(',', $this->validStatuses),
            ], [
                'child_profile_id.required' => 'Please select a child.',
                'child_profile_id.exists' => 'The selected child is invalid.',
                'curriculum_id.required' => 'Please select a program.',
                'curriculum_id.exists' => 'The selected program is invalid.',
                'academic_year_id.required' => 'Please select an academic year.',
                'academic_year_id.exists' => 'The selected academic year is invalid.',
                'payment_plan_id.exists' => 'The selected payment plan is invalid.',
                'status.required' => 'Please select a status.',
                'status.in' => 'The selected status is invalid.',
            ]);

            // Verify the child belongs to the authenticated parent
            $child = ChildProfile::where('id', $validated['child_profile_id'])
                ->where('parent_id', Auth::id())
                ->first();

            if (!$child) {
                $this->addError('child_profile_id', 'You can only enroll your own children.');
                return;
            }

            // Check for duplicate enrollment
            $existingEnrollment = ProgramEnrollment::where('child_profile_id', $validated['child_profile_id'])
                ->where('curriculum_id', $validated['curriculum_id'])
                ->where('academic_year_id', $validated['academic_year_id'])
                ->where('status', 'Active')
                ->first();

            if ($existingEnrollment) {
                $this->addError('curriculum_id', 'This child is already enrolled in this program for the selected academic year.');
                return;
            }

            Log::info('Validation Passed', ['validated_data' => $validated]);

            DB::beginTransaction();
            Log::debug('Database Transaction Started');

            // Create enrollment
            Log::debug('Creating Enrollment Record');
            $enrollment = ProgramEnrollment::create($validated);
            Log::info('Enrollment Created Successfully', [
                'enrollment_id' => $enrollment->id,
                'child_name' => $child->full_name,
                'curriculum_name' => $this->selectedCurriculum->name ?? 'Unknown'
            ]);

            // Log activity
            Log::debug('Logging Activity');
            ActivityLog::logActivity(
                Auth::id(),
                'create',
                "Enrolled {$child->full_name} in {$this->selectedCurriculum->name ?? 'program'}",
                $enrollment,
                [
                    'child_name' => $child->full_name,
                    'child_id' => $child->id,
                    'curriculum_name' => $this->selectedCurriculum->name ?? 'Unknown',
                    'curriculum_id' => $validated['curriculum_id'],
                    'academic_year_name' => $this->selectedAcademicYear->name ?? 'Unknown',
                    'academic_year_id' => $validated['academic_year_id'],
                    'payment_plan_name' => $this->selectedPaymentPlan->name ?? null,
                    'status' => $validated['status'],
                ]
            );

            DB::commit();
            Log::info('Database Transaction Committed');

            // Show success toast
            $this->success("Enrollment for '{$child->full_name}' has been successfully created.");
            Log::info('Success Toast Displayed');

            // Redirect to enrollment show page
            Log::info('Redirecting to Enrollment Show Page', [
                'enrollment_id' => $enrollment->id,
                'route' => 'parent.enrollments.show'
            ]);

            $this->redirect(route('parent.enrollments.show', $enrollment->id));

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation Failed', [
                'errors' => $e->errors(),
                'form_data' => [
                    'child_profile_id' => $this->child_profile_id,
                    'curriculum_id' => $this->curriculum_id,
                    'academic_year_id' => $this->academic_year_id,
                    'payment_plan_id' => $this->payment_plan_id,
                    'status' => $this->status,
                ]
            ]);

            // Re-throw validation exception to show errors to user
            throw $e;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Enrollment Creation Failed', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'form_data' => [
                    'child_profile_id' => $this->child_profile_id,
                    'curriculum_id' => $this->curriculum_id,
                    'academic_year_id' => $this->academic_year_id,
                    'payment_plan_id' => $this->payment_plan_id,
                    'status' => $this->status,
                ]
            ]);

            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Get status options for dropdown
    public function getStatusOptionsProperty(): array
    {
        return [
            'Active' => 'Active',
            'Inactive' => 'Inactive',
        ];
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
    <x-header title="Create New Enrollment" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-arrow-left"
                link="{{ route('parent.enrollments.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left column (2/3) - Form -->
        <div class="lg:col-span-2">
            <x-card title="Enrollment Information">
                <form wire:submit="save" class="space-y-6">
                    <!-- Child Selection -->
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-700">Select Child *</label>
                        <select
                            wire:model.live="child_profile_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            required
                        >
                            <option value="">Choose a child to enroll</option>
                            @foreach($childOptions as $child)
                                <option value="{{ $child['id'] }}">
                                    {{ $child['name'] }}{{ $child['age'] ? ' (' . $child['age'] . ' years old)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('child_profile_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Program Selection -->
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-700">Select Program *</label>
                        <select
                            wire:model.live="curriculum_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            required
                        >
                            <option value="">Choose a program</option>
                            @foreach($curriculumOptions as $curriculum)
                                <option value="{{ $curriculum['id'] }}">
                                    {{ $curriculum['name'] }}{{ $curriculum['code'] ? ' (' . $curriculum['code'] . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if($selectedCurriculum && $selectedCurriculum->description)
                            <p class="mt-1 text-sm text-gray-600">{{ $selectedCurriculum->description }}</p>
                        @endif
                        @error('curriculum_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Academic Year Selection -->
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-700">Academic Year *</label>
                        <select
                            wire:model.live="academic_year_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            required
                        >
                            <option value="">Choose academic year</option>
                            @foreach($academicYearOptions as $year)
                                <option value="{{ $year['id'] }}">{{ $year['name'] }}</option>
                            @endforeach
                        </select>
                        @error('academic_year_id')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Payment Plan Selection -->
                    @if(count($paymentPlanOptions) > 0)
                        <div>
                            <label class="block mb-2 text-sm font-medium text-gray-700">Payment Plan (Optional)</label>
                            <select
                                wire:model.live="payment_plan_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="">No payment plan selected</option>
                                @foreach($paymentPlanOptions as $plan)
                                    <option value="{{ $plan['id'] }}">
                                        {{ $plan['name'] }}{{ $plan['amount'] ? ' - $' . number_format($plan['amount'], 2) : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @if($selectedPaymentPlan && $selectedPaymentPlan->description)
                                <p class="mt-1 text-sm text-gray-600">{{ $selectedPaymentPlan->description }}</p>
                            @endif
                            @error('payment_plan_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    <!-- Status Selection -->
                    <div>
                        <label class="block mb-2 text-sm font-medium text-gray-700">Status *</label>
                        <select
                            wire:model.live="status"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                            required
                        >
                            @foreach($this->statusOptions as $value => $label)
                                <option value="{{ $value }}" {{ $status == $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('status')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end pt-6">
                        <x-button
                            label="Cancel"
                            link="{{ route('parent.enrollments.index') }}"
                            class="mr-2"
                        />
                        <x-button
                            label="Create Enrollment"
                            icon="o-academic-cap"
                            type="submit"
                            class="btn-primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>

        <!-- Right column (1/3) - Preview and Help -->
        <div class="space-y-6">
            <!-- Enrollment Preview Card -->
            <x-card title="Enrollment Preview">
                <div class="p-4 rounded-lg bg-base-200">
                    @if($selectedChild)
                        <div class="flex items-center mb-4">
                            <div class="mr-4 avatar placeholder">
                                <div class="w-12 h-12 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                    <span class="text-sm font-bold">{{ $selectedChild->initials }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="font-semibold">{{ $selectedChild->full_name }}</div>
                                @if($selectedChild->age)
                                    <div class="text-sm text-gray-500">{{ $selectedChild->age }} years old</div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="mb-4 text-center text-gray-500">
                            <x-icon name="o-user" class="w-12 h-12 mx-auto mb-2 text-gray-300" />
                            <p>Select a child to see preview</p>
                        </div>
                    @endif

                    <div class="space-y-3 text-sm">
                        @if($selectedCurriculum)
                            <div>
                                <strong>Program:</strong>
                                <div class="mt-1">{{ $selectedCurriculum->name }}</div>
                                @if($selectedCurriculum->code)
                                    <div class="text-xs text-gray-500">Code: {{ $selectedCurriculum->code }}</div>
                                @endif
                            </div>
                        @endif

                        @if($selectedAcademicYear)
                            <div><strong>Academic Year:</strong> {{ $selectedAcademicYear->name }}</div>
                        @endif

                        @if($selectedPaymentPlan)
                            <div>
                                <strong>Payment Plan:</strong> {{ $selectedPaymentPlan->name }}
                                @if($selectedPaymentPlan->amount)
                                    <div class="font-medium text-green-600">${{ number_format($selectedPaymentPlan->amount, 2) }}</div>
                                @endif
                            </div>
                        @endif

                        @if($status)
                            <div>
                                <strong>Status:</strong>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ml-2 {{ match($status) {
                                    'Active' => 'bg-green-100 text-green-800',
                                    'Inactive' => 'bg-gray-100 text-gray-600',
                                    default => 'bg-gray-100 text-gray-600'
                                } }}">
                                    {{ $status }}
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Available Children -->
            @if(count($childOptions) > 0)
                <x-card title="Your Children">
                    <div class="space-y-3">
                        @foreach($childOptions as $child)
                            <div class="flex items-center p-2 border rounded-md {{ $child_profile_id == $child['id'] ? 'border-blue-300 bg-blue-50' : 'border-gray-200' }}">
                                <div class="mr-3 avatar placeholder">
                                    <div class="w-8 h-8 text-white rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                                        <span class="text-xs font-bold">
                                            {{ substr($child['name'], 0, 1) }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <div class="text-sm font-medium">{{ $child['name'] }}</div>
                                    @if($child['age'])
                                        <div class="text-xs text-gray-500">{{ $child['age'] }} years old</div>
                                    @endif
                                </div>
                                @if($child_profile_id == $child['id'])
                                    <x-icon name="o-check" class="w-4 h-4 text-blue-600" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @else
                <x-card title="No Children Available" class="border-yellow-200 bg-yellow-50">
                    <div class="text-center">
                        <x-icon name="o-exclamation-triangle" class="w-8 h-8 mx-auto mb-2 text-yellow-600" />
                        <p class="text-sm text-yellow-800">You need to add a child before creating an enrollment.</p>
                        <x-button
                            label="Add Child"
                            icon="o-plus"
                            link="{{ route('parent.children.create') }}"
                            class="mt-2 btn-sm btn-primary"
                        />
                    </div>
                </x-card>
            @endif

            <!-- Help Card -->
            <x-card title="Help & Information">
                <div class="space-y-4 text-sm">
                    <div>
                        <div class="font-semibold">Enrollment Process</div>
                        <p class="text-gray-600">Select your child, choose a program, and specify the academic year to create an enrollment.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Payment Plans</div>
                        <p class="text-gray-600">Payment plans help manage tuition costs. You can select one during enrollment or add it later.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Duplicate Enrollments</div>
                        <p class="text-gray-600">A child cannot be enrolled in the same program twice for the same academic year.</p>
                    </div>

                    <div>
                        <div class="font-semibold">Status Options</div>
                        <ul class="mt-2 space-y-1 text-gray-600">
                            <li><strong>Active:</strong> Child is actively enrolled</li>
                            <li><strong>Inactive:</strong> Enrollment is temporarily disabled</li>
                        </ul>
                    </div>
                </div>
            </x-card>

            <!-- Important Notice -->
            <x-card title="Important Notice" class="border-blue-200 bg-blue-50">
                <div class="space-y-2 text-sm">
                    <div class="flex items-start">
                        <x-icon name="o-information-circle" class="w-5 h-5 text-blue-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-blue-800">After Enrollment</div>
                            <p class="text-blue-700">Once enrolled, you'll be able to track attendance, view exam results, and manage payments for your child.</p>
                        </div>
                    </div>

                    <div class="flex items-start">
                        <x-icon name="o-credit-card" class="w-5 h-5 text-blue-600 mr-2 mt-0.5" />
                        <div>
                            <div class="font-semibold text-blue-800">Payment Processing</div>
                            <p class="text-blue-700">Invoices will be generated based on the selected payment plan. You can view and pay them online.</p>
                        </div>
                    </div>
                </div>
            </x-card>
        </div>
    </div>
</div>
