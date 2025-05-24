<?php

use App\Models\ChildProfile;
use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\PaymentPlan;
use App\Models\ProgramEnrollment;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Rule;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('New Enrollment')] class extends Component {
    use Toast;

    #[Rule('required')]
    public ?int $childProfileId = null;

    #[Rule('required')]
    public ?int $academicYearId = null;

    #[Rule('required')]
    public ?int $curriculumId = null;

    #[Rule('required')]
    public ?int $paymentPlanId = null;

    public string $startDate;
    public string $status = 'pending';
    public ?string $notes = null;

    // Collections for dropdowns
    public $children = [];
    public $academicYears = [];
    public $curricula = [];
    public $paymentPlans = [];

    public function mount(): void
    {
        // Set default start date to today
        $this->startDate = date('Y-m-d');

        // Load collections for dropdowns
        $this->loadCollections();
    }

    protected function loadCollections(): void
    {
        // Get current user's children
        $this->children = ChildProfile::where('parent_profile_id', Auth::user()->parentProfile->id)
            ->with('user')
            ->get();

        // Get active academic years
        $this->academicYears = AcademicYear::where('is_current', true)
            ->orWhere('start_date', '>=', now())
            ->orderBy('start_date')
            ->get();

        // Load curricula based on selected academic year
        if ($this->academicYearId) {
            $this->curricula = Curriculum::whereHas('academicYears', function ($q) {
                $q->where('academic_year_id', $this->academicYearId);
            })
            ->orderBy('name')
            ->get();
        } else {
            $this->curricula = collect();
        }

        // Load payment plans based on selected curriculum
        if ($this->curriculumId) {
            $this->paymentPlans = PaymentPlan::where('curriculum_id', $this->curriculumId)
                ->orderBy('amount')
                ->get();
        } else {
            $this->paymentPlans = collect();
        }
    }

    public function updatedAcademicYearId(): void
    {
        $this->curriculumId = null;
        $this->paymentPlanId = null;
        $this->loadCollections();
    }

    public function updatedCurriculumId(): void
    {
        $this->paymentPlanId = null;
        $this->loadCollections();
    }

    public function save(): void
    {
        $this->validate();

        try {
            // Create enrollment
            $enrollment = ProgramEnrollment::create([
                'child_profile_id' => $this->childProfileId,
                'academic_year_id' => $this->academicYearId,
                'curriculum_id' => $this->curriculumId,
                'payment_plan_id' => $this->paymentPlanId,
                'start_date' => $this->startDate,
                'status' => $this->status,
                'notes' => $this->notes,
                'created_by' => Auth::id()
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created new enrollment for {$enrollment->childProfile->user->name}",
                ProgramEnrollment::class,
                $enrollment->id,
                [
                    'child_profile_id' => $this->childProfileId,
                    'academic_year_id' => $this->academicYearId,
                    'curriculum_id' => $this->curriculumId,
                    'payment_plan_id' => $this->paymentPlanId,
                    'ip' => request()->ip()
                ]
            );

            // Show success message
            $this->success('Enrollment created successfully!');

            // Redirect to enrollment detail page
            $this->redirect(route('parent.enrollments.show', $enrollment));
        } catch (\Exception $e) {
            // Show error message
            $this->error('Failed to create enrollment: ' . $e->getMessage());
        }
    }
};
?>

<div class="container py-6 mx-auto">
    <x-header title="New Enrollment" separator>
        <x-slot:subtitle>
            Enroll your child in a program
        </x-slot:subtitle>

        <x-slot:actions>
            <div class="flex space-x-2">
                <x-button
                    label="Back to Enrollments"
                    icon="o-arrow-left"
                    link="{{ route('parent.enrollments.index') }}"
                    color="secondary"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <x-card class="mt-6">
        <form wire:submit="save" class="space-y-6">
            <!-- Step 1: Select Child -->
            <div>
                <h3 class="mb-4 text-lg font-medium text-gray-900">Step 1: Select Child</h3>

                <div class="grid grid-cols-1 gap-6 mb-6">
                    <div>
                        <x-select
                            label="Child"
                            placeholder="Select a child"
                            :options="$children"
                            wire:model="childProfileId"
                            option-label="user.name"
                            option-value="id"
                            option-description="date_of_birth"
                            hint="Select the child you want to enroll"
                            required
                        />
                    </div>
                </div>
            </div>

            <!-- Step 2: Select Program -->
            <div>
                <h3 class="mb-4 text-lg font-medium text-gray-900">Step 2: Select Program</h3>

                <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-2">
                    <div>
                        <x-select
                            label="Academic Year"
                            placeholder="Select academic year"
                            :options="$academicYears"
                            wire:model.live="academicYearId"
                            option-label="name"
                            option-value="id"
                            option-description="description"
                            hint="Select the academic year for enrollment"
                            required
                        >
                            <x-slot:prepend>
                                <div class="flex items-center justify-center w-10 h-10">
                                    <x-icon name="o-academic-cap" class="w-5 h-5" />
                                </div>
                            </x-slot:prepend>
                        </x-select>
                    </div>

                    <div>
                        <x-select
                            label="Curriculum"
                            placeholder="Select a curriculum"
                            :options="$curricula"
                            wire:model.live="curriculumId"
                            option-label="name"
                            option-value="id"
                            option-description="description"
                            hint="Select the curriculum for enrollment"
                            required
                            :disabled="!$academicYearId"
                        >
                            <x-slot:prepend>
                                <div class="flex items-center justify-center w-10 h-10">
                                    <x-icon name="o-book-open" class="w-5 h-5" />
                                </div>
                            </x-slot:prepend>
                        </x-select>
                    </div>
                </div>
            </div>

            <!-- Step 3: Payment Plan -->
            <div>
                <h3 class="mb-4 text-lg font-medium text-gray-900">Step 3: Choose Payment Plan</h3>

                <div class="grid grid-cols-1 gap-6 mb-6">
                    <div>
                        <x-select
                            label="Payment Plan"
                            placeholder="Select a payment plan"
                            :options="$paymentPlans"
                            wire:model="paymentPlanId"
                            option-label="type"
                            option-value="id"
                            option-description="amount"
                            hint="Select your preferred payment plan"
                            required
                            :disabled="!$curriculumId"
                        >
                            <x-slot:prepend>
                                <div class="flex items-center justify-center w-10 h-10">
                                    <x-icon name="o-currency-dollar" class="w-5 h-5" />
                                </div>
                            </x-slot:prepend>
                        </x-select>
                    </div>
                </div>

                <!-- Display payment plan details when selected -->
                @if($paymentPlanId)
                    @php
                        $selectedPlan = $paymentPlans->firstWhere('id', $paymentPlanId);
                    @endphp

                    <div class="p-4 rounded-lg bg-gray-50">
                        <h4 class="mb-2 font-medium text-gray-900">Selected Payment Plan Details</h4>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-sm text-gray-500">Plan Type</p>
                                <p class="font-medium">{{ $selectedPlan->type }}</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Amount</p>
                                <p class="font-medium">{{ number_format($selectedPlan->amount, 2) }}</p>
                            </div>
                            @if($selectedPlan->due_day)
                            <div>
                                <p class="text-sm text-gray-500">Due Day</p>
                                <p class="font-medium">{{ $selectedPlan->due_day }} of each month</p>
                            </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <!-- Step 4: Additional Information -->
            <div>
                <h3 class="mb-4 text-lg font-medium text-gray-900">Step 4: Additional Information</h3>

                <div class="grid grid-cols-1 gap-6 mb-6 md:grid-cols-2">
                    <div>
                        <x-input
                            type="date"
                            label="Start Date"
                            wire:model="startDate"
                            hint="When will the enrollment begin"
                            required
                        />
                    </div>

                    <div>
                        <x-select
                            label="Status"
                            wire:model="status"
                            :options="[
                                'pending' => 'Pending',
                                'active' => 'Active',
                                'on_hold' => 'On Hold'
                            ]"
                            hint="Initial enrollment status"
                            required
                        >
                            <x-slot:prepend>
                                <div class="flex items-center justify-center w-10 h-10">
                                    <x-icon name="o-check-circle" class="w-5 h-5" />
                                </div>
                            </x-slot:prepend>
                        </x-select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 mb-6">
                    <div>
                        <x-textarea
                            label="Notes"
                            wire:model="notes"
                            hint="Any special notes or considerations for this enrollment"
                            rows="3"
                        />
                    </div>
                </div>
            </div>

            <!-- Confirmation and Submit -->
            <div class="pt-4 border-t border-gray-200">
                <div class="flex justify-end">
                    <x-button
                        type="submit"
                        label="Create Enrollment"
                        icon="o-check"
                        color="primary"
                    />
                </div>
            </div>
        </form>
    </x-card>
</div>
