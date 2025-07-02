<?php

use App\Models\PaymentPlan;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Payment Plan')] class extends Component {
    use Toast;

    #[Rule('required|string|max:255')]
    public string $name = '';

    #[Rule('required|string|in:monthly,quarterly,semi-annual,annual,one-time')]
    public string $type = '';

    #[Rule('required|numeric|min:0')]
    public string $amount = '';

    #[Rule('required|string|max:3')]
    public string $currency = 'USD';

    #[Rule('nullable|string|max:1000')]
    public ?string $description = null;

    #[Rule('required|exists:curricula,id')]
    public string $curriculum_id = '';

    #[Rule('boolean')]
    public bool $is_active = true;

    #[Rule('nullable|integer|min:1|max:12')]
    public ?int $installments = 1;

    #[Rule('nullable|string|in:monthly,quarterly,semi-annual,annual,one-time')]
    public ?string $frequency = null;

    // Available currencies
    public array $currencies = [
        'USD' => 'USD ($)',
        'EUR' => 'EUR (€)',
        'GBP' => 'GBP (£)',
    ];

    // Initialize component
    public function mount(): void
    {
        // Set default values
        $this->frequency = $this->type;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed create payment plan page',
            PaymentPlan::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Get payment plan types from the model
    public function getPaymentTypesProperty(): array
    {
        return [
            ['value' => 'monthly', 'label' => 'Monthly'],
            ['value' => 'quarterly', 'label' => 'Quarterly'],
            ['value' => 'semi-annual', 'label' => 'Semi-Annual'],
            ['value' => 'annual', 'label' => 'Annual'],
            ['value' => 'one-time', 'label' => 'One-Time'],
        ];
    }

    // Get curricula for dropdown
    public function getCurriculaProperty()
    {
        return Curriculum::orderBy('name')->get();
    }

    // Update frequency and installments when type changes
    public function updatedType(): void
    {
        $this->frequency = $this->type;

        // Set default installments based on type
        $this->installments = match($this->type) {
            'monthly' => 12,
            'quarterly' => 4,
            'semi-annual' => 2,
            'annual' => 1,
            'one-time' => 1,
            default => 1
        };
    }

    // Generate suggested name based on type and curriculum
    public function updatedCurriculumId(): void
    {
        if ($this->curriculum_id && $this->type) {
            $curriculum = Curriculum::find($this->curriculum_id);
            if ($curriculum) {
                $typeLabel = collect($this->paymentTypes)->firstWhere('value', $this->type)['label'] ?? ucfirst($this->type);
                $this->name = "{$curriculum->name} - {$typeLabel} Plan";
            }
        }
    }

    // Also update name when type changes
    public function updatedTypeForName(): void
    {
        $this->updatedCurriculumId();
    }

    // Save the new payment plan
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Check for duplicate plans
            $existingPlan = PaymentPlan::where('curriculum_id', $this->curriculum_id)
                ->where('type', $this->type)
                ->where('is_active', true)
                ->first();

            if ($existingPlan) {
                $this->error('An active payment plan of this type already exists for the selected curriculum.');
                return;
            }

            // Create payment plan
            $paymentPlan = PaymentPlan::create([
                'name' => $this->name,
                'type' => $this->type,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'description' => $this->description,
                'curriculum_id' => $this->curriculum_id,
                'is_active' => $this->is_active,
                'installments' => $this->installments,
                'frequency' => $this->frequency,
            ]);

            // Get curriculum name for logging
            $curriculumName = Curriculum::find($this->curriculum_id)->name ?? 'Unknown curriculum';

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created payment plan: {$this->name}",
                PaymentPlan::class,
                $paymentPlan->id,
                [
                    'payment_plan_name' => $this->name,
                    'payment_plan_type' => $this->type,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                    'curriculum_name' => $curriculumName,
                    'installments' => $this->installments,
                    'is_active' => $this->is_active
                ]
            );

            DB::commit();

            // Success message and redirect
            $this->success("Payment plan '{$this->name}' has been created successfully.");
            $this->redirect(route('admin.payment-plans.index'));
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Save and create another
    public function saveAndCreateAnother(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Check for duplicate plans
            $existingPlan = PaymentPlan::where('curriculum_id', $this->curriculum_id)
                ->where('type', $this->type)
                ->where('is_active', true)
                ->first();

            if ($existingPlan) {
                $this->error('An active payment plan of this type already exists for the selected curriculum.');
                return;
            }

            // Create payment plan
            $paymentPlan = PaymentPlan::create([
                'name' => $this->name,
                'type' => $this->type,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'description' => $this->description,
                'curriculum_id' => $this->curriculum_id,
                'is_active' => $this->is_active,
                'installments' => $this->installments,
                'frequency' => $this->frequency,
            ]);

            // Get curriculum name for logging
            $curriculumName = Curriculum::find($this->curriculum_id)->name ?? 'Unknown curriculum';

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created payment plan: {$this->name}",
                PaymentPlan::class,
                $paymentPlan->id,
                [
                    'payment_plan_name' => $this->name,
                    'payment_plan_type' => $this->type,
                    'amount' => $this->amount,
                    'currency' => $curriculumName,
                    'curriculum_name' => $curriculumName,
                    'installments' => $this->installments,
                    'is_active' => $this->is_active
                ]
            );

            DB::commit();

            // Reset form for new entry
            $this->reset(['name', 'type', 'amount', 'description', 'curriculum_id', 'installments', 'frequency']);
            $this->currency = 'USD';
            $this->is_active = true;

            $this->success("Payment plan created successfully. You can create another one.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Cancel and go back to index
    public function cancel(): void
    {
        $this->redirect(route('admin.payment-plans.index'));
    }

    // Preview calculations
    public function getCalculationsProperty(): array
    {
        if (!$this->amount || !$this->installments) {
            return [];
        }

        $amount = (float) $this->amount;
        $installments = (int) $this->installments;
        $total = $amount * $installments;

        return [
            'per_payment' => $amount,
            'total_payments' => $installments,
            'total_amount' => $total,
            'frequency_text' => match($this->type) {
                'monthly' => 'Every month',
                'quarterly' => 'Every 3 months',
                'semi-annual' => 'Every 6 months',
                'annual' => 'Once per year',
                'one-time' => 'One-time payment',
                default => ucfirst($this->type ?? 'Unknown')
            }
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create Payment Plan" separator>
        <x-slot:actions>
            <x-button
                label="Back to Payment Plans"
                icon="o-arrow-left"
                link="{{ route('admin.payment-plans.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <!-- Form Card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-6 space-y-6">
            <!-- Basic Information -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <x-select
                        label="Curriculum"
                        placeholder="Select a curriculum"
                        :options="$this->curricula"
                        wire:model.live="curriculum_id"
                        option-label="name"
                        option-value="id"
                        required
                        hint="Select the curriculum this payment plan applies to"
                    />
                </div>

                <div>
                    <x-select
                        label="Payment Type"
                        placeholder="Select payment type"
                        :options="$this->paymentTypes"
                        wire:model.live="type"
                        option-label="label"
                        option-value="value"
                        required
                        hint="Select the frequency of payments"
                    />
                </div>
            </div>

            <!-- Plan Name (auto-generated but editable) -->
            <div>
                <x-input
                    label="Plan Name"
                    wire:model="name"
                    placeholder="e.g., Standard Monthly Plan"
                    hint="Enter a descriptive name for this payment plan (auto-generated based on curriculum and type)"
                    required
                />
            </div>

            <!-- Payment Details -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <div>
                    <x-input
                        label="Amount"
                        wire:model.live="amount"
                        placeholder="0.00"
                        hint="Enter the payment amount per installment"
                        required
                    />
                </div>

                <div>
                    <x-select
                        label="Currency"
                        :options="$currencies"
                        wire:model="currency"
                        required
                        hint="Select the currency"
                    />
                </div>

                <div>
                    <x-input
                        type="number"
                        label="Number of Installments"
                        wire:model.live="installments"
                        min="1"
                        max="12"
                        placeholder="e.g., 12"
                        hint="Total number of payments (automatically set based on type)"
                    />
                </div>
            </div>

            <!-- Active Status -->
            <div class="flex items-center space-x-4">
                <x-checkbox
                    label="Active Plan"
                    wire:model="is_active"
                    hint="Check to make this payment plan immediately available"
                />
            </div>

            <!-- Description -->
            <div>
                <x-textarea
                    label="Description"
                    wire:model="description"
                    placeholder="Optional description of this payment plan..."
                    hint="Provide additional details about this payment plan"
                    rows="3"
                />
            </div>

            <!-- Payment Summary Preview -->
            @if($this->calculations)
                <div class="p-4 border rounded-lg bg-blue-50 border-blue-200">
                    <h4 class="mb-3 font-medium text-blue-900">Payment Plan Preview</h4>
                    <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-2 lg:grid-cols-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600">
                                {{ $currency === 'USD' ? '$' : ($currency === 'EUR' ? '€' : '£') }}{{ number_format($this->calculations['per_payment'], 2) }}
                            </div>
                            <div class="text-blue-700">Per Payment</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $this->calculations['total_payments'] }}</div>
                            <div class="text-blue-700">Total Payments</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600">
                                {{ $currency === 'USD' ? '$' : ($currency === 'EUR' ? '€' : '£') }}{{ number_format($this->calculations['total_amount'], 2) }}
                            </div>
                            <div class="text-green-700">Total Amount</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-medium text-gray-600">{{ $this->calculations['frequency_text'] }}</div>
                            <div class="text-gray-500">Payment Schedule</div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Duplicate Check Warning -->
            @if($curriculum_id && $type)
                @php
                    $existingPlan = \App\Models\PaymentPlan::where('curriculum_id', $curriculum_id)
                        ->where('type', $type)
                        ->where('is_active', true)
                        ->first();
                @endphp
                @if($existingPlan)
                    <div class="p-4 border rounded-lg bg-red-50 border-red-200">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-2 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                            <span class="text-red-700">
                                <strong>Warning:</strong> An active {{ ucfirst($type) }} payment plan already exists for this curriculum: "{{ $existingPlan->name }}"
                            </span>
                        </div>
                    </div>
                @endif
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancel" />
            <x-button
                label="Save & Create Another"
                icon="o-plus"
                wire:click="saveAndCreateAnother"
                class="btn-secondary"
                spinner="saveAndCreateAnother"
            />
            <x-button
                label="Create Payment Plan"
                icon="o-check"
                wire:click="save"
                class="btn-primary"
                spinner="save"
            />
        </x-slot:actions>
    </x-card>
</div>
