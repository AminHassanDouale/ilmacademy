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

    #[Rule('required|string|max:50')]
    public string $type = '';

    #[Rule('required|numeric|min:0')]
    public string $amount = '';

    #[Rule('nullable|integer|min:1|max:31')]
    public ?int $due_day = null;

    #[Rule('required|exists:curricula,id')]
    public string $curriculum_id = '';

    // Predefined payment plan types
    public array $planTypes = [
        'Monthly',
        'Quarterly',
        'Annual',
        'One-Time'
    ];

    // Initialize component
    public function mount(PaymentPlan $paymentPlan): void
    {
        $this->paymentPlan = $paymentPlan;
        $this->type = $paymentPlan->type;
        $this->amount = $paymentPlan->amount;
        $this->due_day = $paymentPlan->due_day;
        $this->curriculum_id = $paymentPlan->curriculum_id;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit payment plan page for {$paymentPlan->type}",
            PaymentPlan::class,
            $paymentPlan->id,
            ['ip' => request()->ip()]
        );
    }

    // Get curricula for dropdown
    public function curricula()
    {
        return Curriculum::orderBy('name')->get();
    }

    // Save the payment plan updates
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // Track old values for logging
            $oldValues = [
                'type' => $this->paymentPlan->type,
                'amount' => $this->paymentPlan->amount,
                'due_day' => $this->paymentPlan->due_day,
                'curriculum_id' => $this->paymentPlan->curriculum_id
            ];

            // Update payment plan
            $this->paymentPlan->update([
                'type' => $this->type,
                'amount' => $this->amount,
                'due_day' => $this->due_day,
                'curriculum_id' => $this->curriculum_id,
            ]);

            // Get curriculum name for logging
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
                    "Updated payment plan: {$this->type}",
                    PaymentPlan::class,
                    $this->paymentPlan->id,
                    [
                        'payment_plan_type' => $this->type,
                        'changes' => $changes,
                        'curriculum_name' => $curriculumName
                    ]
                );
            }

            DB::commit();

            // Success message and redirect
            $this->success("Payment plan '{$this->type}' has been updated successfully.");
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
    <x-header title="Edit Payment Plan: {{ $paymentPlan->type }}" separator>
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
    <x-card class="max-w-2xl mx-auto">
        <div class="p-4 space-y-6">
            <div>
                <x-select
                    label="Curriculum"
                    placeholder="Select a curriculum"
                    :options="$this->curricula()"
                    wire:model="curriculum_id"
                    option-label="name"
                    option-value="id"
                    required
                    hint="Select the curriculum this payment plan applies to"
                />
                @error('curriculum_id') <x-error>{{ $message }}</x-error> @enderror
            </div>

            <div>
                <x-select
                    label="Payment Plan Type"
                    placeholder="Select a payment plan type"
                    :options="$planTypes"
                    wire:model="type"
                    required
                    hint="Select the type of payment schedule"
                />
                @error('type') <x-error>{{ $message }}</x-error> @enderror
            </div>

            <div>
                <x-input
                    label="Amount"
                    wire:model="amount"
                    placeholder="0.00"
                    prefix="$"
                    hint="Enter the payment amount"
                    required
                />
                @error('amount') <x-error>{{ $message }}</x-error> @enderror
            </div>

            <div>
                <x-input
                    type="number"
                    label="Due Day of Month"
                    wire:model="due_day"
                    min="1"
                    max="31"
                    placeholder="e.g., 15"
                    hint="Day of the month when payment is due (optional, leave empty for one-time payments)"
                />
                @error('due_day') <x-error>{{ $message }}</x-error> @enderror
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancel" />
            <x-button label="Update Payment Plan" icon="o-check" wire:click="save" class="btn-primary" spinner="save" />
        </x-slot:actions>
    </x-card>
</div>
