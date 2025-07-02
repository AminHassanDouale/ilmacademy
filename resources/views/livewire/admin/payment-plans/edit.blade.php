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

new #[Title('Edit Payment Plan')] class extends Component {
    use Toast;

    public PaymentPlan $paymentPlan;

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
    public ?int $installments = null;

    #[Rule('nullable|string|in:monthly,quarterly,semi-annual,annual,one-time')]
    public ?string $frequency = null;

    // Available currencies
    public array $currencies = [
        'USD' => 'USD ($)',
        'EUR' => 'EUR (€)',
        'GBP' => 'GBP (£)',
    ];

    // Initialize component
    public function mount(PaymentPlan $paymentPlan): void
    {
        $this->paymentPlan = $paymentPlan;
        $this->name = $paymentPlan->name ?? '';
        $this->type = $paymentPlan->type;
        $this->amount = (string) $paymentPlan->amount;
        $this->currency = $paymentPlan->currency ?? 'USD';
        $this->description = $paymentPlan->description;
        $this->curriculum_id = (string) $paymentPlan->curriculum_id;
        $this->is_active = $paymentPlan->is_active ?? true;
        $this->installments = $paymentPlan->installments;
        $this->frequency = $paymentPlan->frequency ?? $paymentPlan->type;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit payment plan page for {$paymentPlan->name}",
            PaymentPlan::class,
            $paymentPlan->id,
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

    // Update frequency when type changes
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
            default => null
        };
    }

    // Save the payment plan updates
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Track old values for logging
            $oldValues = [
                'name' => $this->paymentPlan->name,
                'type' => $this->paymentPlan->type,
                'amount' => $this->paymentPlan->amount,
                'currency' => $this->paymentPlan->currency,
                'description' => $this->paymentPlan->description,
                'curriculum_id' => $this->paymentPlan->curriculum_id,
                'is_active' => $this->paymentPlan->is_active,
                'installments' => $this->paymentPlan->installments,
                'frequency' => $this->paymentPlan->frequency,
            ];

            // Update payment plan
            $this->paymentPlan->update([
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

            // Get curriculum names for logging
            $curriculumName = Curriculum::find($this->curriculum_id)->name ?? 'Unknown curriculum';
            $oldCurriculumName = Curriculum::find($oldValues['curriculum_id'])->name ?? 'Unknown curriculum';

            // Create change details for logging
            $changes = [];
            foreach ($oldValues as $key => $oldValue) {
                $newValue = $this->paymentPlan->$key;

                if ($oldValue != $newValue) {
                    if ($key === 'curriculum_id') {
                        $changes[$key] = [
                            'old' => $oldValue . ' (' . $oldCurriculumName . ')',
                            'new' => $newValue . ' (' . $curriculumName . ')'
                        ];
                    } else {
                        $changes[$key] = [
                            'old' => $oldValue,
                            'new' => $newValue
                        ];
                    }
                }
            }

            // Log activity if there are changes
            if (!empty($changes)) {
                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Updated payment plan: {$this->name}",
                    PaymentPlan::class,
                    $this->paymentPlan->id,
                    [
                        'payment_plan_name' => $this->name,
                        'payment_plan_type' => $this->type,
                        'changes' => $changes,
                        'curriculum_name' => $curriculumName
                    ]
                );
            }

            DB::commit();

            // Success message and redirect
            $this->success("Payment plan '{$this->name}' has been updated successfully.");
            $this->redirect(route('admin.payment-plans.index'));
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
};?>

<div>
    <!-- Page header -->
    <x-header title="Edit Payment Plan: {{ $paymentPlan->name ?? $paymentPlan->type }}" separator>
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
                    <x-input
                        label="Plan Name"
                        wire:model="name"
                        placeholder="e.g., Standard Monthly Plan"
                        hint="Enter a descriptive name for this payment plan"
                        required
                    />
                </div>

                <div>
                    <x-select
                        label="Curriculum"
                        placeholder="Select a curriculum"
                        :options="$this->curricula"
                        wire:model="curriculum_id"
                        option-label="name"
                        option-value="id"
                        required
                        hint="Select the curriculum this payment plan applies to"
                    />
                </div>
            </div>

            <!-- Payment Details -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
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

                <div>
                    <x-input
                        label="Amount"
                        wire:model="amount"
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
            </div>

            <!-- Advanced Settings -->
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <x-input
                        type="number"
                        label="Number of Installments"
                        wire:model="installments"
                        min="1"
                        max="12"
                        placeholder="e.g., 12"
                        hint="Total number of payments (automatically set based on type)"
                    />
                </div>

                <div class="flex items-center space-x-4">
                    <x-checkbox
                        label="Active Plan"
                        wire:model="is_active"
                        hint="Uncheck to disable this payment plan"
                    />
                </div>
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

            <!-- Payment Summary -->
            @if($amount && $installments)
                <div class="p-4 border rounded-lg bg-gray-50">
                    <h4 class="mb-2 font-medium text-gray-900">Payment Summary</h4>
                    <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                        <div>
                            <span class="text-gray-600">Per Payment:</span>
                            <span class="ml-2 font-medium">
                                {{ $currency === 'USD' ? '$' : ($currency === 'EUR' ? '€' : '£') }}{{ number_format((float)$amount, 2) }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-600">Total Payments:</span>
                            <span class="ml-2 font-medium">{{ $installments ?? 1 }}</span>
                        </div>
                        <div>
                            <span class="text-gray-600">Total Amount:</span>
                            <span class="ml-2 font-medium text-blue-600">
                                {{ $currency === 'USD' ? '$' : ($currency === 'EUR' ? '€' : '£') }}{{ number_format((float)$amount * ($installments ?? 1), 2) }}
                            </span>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancel" />
            <x-button
                label="Update Payment Plan"
                icon="o-check"
                wire:click="save"
                class="btn-primary"
                spinner="save"
            />
        </x-slot:actions>
    </x-card>
</div>
