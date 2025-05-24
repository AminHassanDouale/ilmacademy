<?php

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Academic Year')] class extends Component {
    use Toast;

    public AcademicYear $academicYear;

    #[Rule('required|string|max:100')]
    public string $name = '';

    #[Rule('required|date')]
    public string $start_date = '';

    #[Rule('required|date|after:start_date')]
    public string $end_date = '';

    #[Rule('boolean')]
    public bool $is_current = false;

    // Initialize component
    public function mount(AcademicYear $academicYear): void
    {
        $this->academicYear = $academicYear;
        $this->name = $academicYear->name;
        $this->start_date = $academicYear->start_date->format('Y-m-d');
        $this->end_date = $academicYear->end_date->format('Y-m-d');
        $this->is_current = (bool) $academicYear->is_current;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit academic year page for {$academicYear->name}",
            AcademicYear::class,
            $academicYear->id,
            ['ip' => request()->ip()]
        );
    }

    // Updated unique rule for when the name changes
    public function updatedName(): void
    {
        $this->validateOnly('name', [
            'name' => "required|string|max:100|unique:academic_years,name,{$this->academicYear->id}"
        ]);
    }

    // Save the academic year updates
    public function save(): void
    {
        $this->validate([
            'name' => "required|string|max:100|unique:academic_years,name,{$this->academicYear->id}",
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_current' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            // Track old values for logging
            $oldValues = [
                'name' => $this->academicYear->name,
                'start_date' => $this->academicYear->start_date->format('Y-m-d'),
                'end_date' => $this->academicYear->end_date->format('Y-m-d'),
                'is_current' => (bool) $this->academicYear->is_current
            ];

            // If this is set as current, unset any existing current academic year
            if ($this->is_current && !$this->academicYear->is_current) {
                AcademicYear::where('is_current', true)
                    ->where('id', '!=', $this->academicYear->id)
                    ->update(['is_current' => false]);
            }

            // Update academic year
            $this->academicYear->update([
                'name' => $this->name,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'is_current' => $this->is_current,
            ]);

            // Create change details for logging
            $changes = [];
            foreach ($oldValues as $key => $oldValue) {
                $newValue = $this->academicYear->$key;
                if ($key === 'start_date' || $key === 'end_date') {
                    $newValue = $this->academicYear->$key->format('Y-m-d');
                }

                if ($oldValue != $newValue) {
                    $changes[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }

            // Log activity if there are changes
            if (!empty($changes)) {
                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Updated academic year: {$this->name}",
                    AcademicYear::class,
                    $this->academicYear->id,
                    [
                        'academic_year_name' => $this->name,
                        'changes' => $changes
                    ]
                );
            }

            DB::commit();

            // Success message and redirect
            $this->success("Academic year '{$this->name}' has been updated successfully.");
            $this->redirect(route('admin.academic-years.index'));
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    // Cancel and go back to index
    public function cancel(): void
    {
        $this->redirect(route('admin.academic-years.index'));
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Edit Academic Year: {{ $academicYear->name }}" separator>
        <x-slot:actions>
            <x-button
                label="Back to Academic Years"
                icon="o-arrow-left"
                link="{{ route('admin.academic-years.index') }}"
                class="btn-ghost"
            />
        </x-slot:actions>
    </x-header>

    <!-- Form Card -->
    <x-card class="max-w-2xl mx-auto">
        <div class="p-4 space-y-6">
            <div>
                <x-input
                    label="Academic Year Name"
                    wire:model="name"
                    placeholder="e.g., 2025-2026 Academic Year"
                    hint="Enter a unique name for this academic year"
                    required
                />
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <x-input
                        type="date"
                        label="Start Date"
                        wire:model="start_date"
                        required
                    />
                </div>
                <div>
                    <x-input
                        type="date"
                        label="End Date"
                        wire:model="end_date"
                        required
                    />
                </div>
            </div>

            <div>
                <x-checkbox
                    label="Set as current academic year"
                    wire:model="is_current"
                    hint="If checked, this will be set as the current academic year and any previously set current year will be unset."
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancel" />
            <x-button label="Update Academic Year" icon="o-check" wire:click="save" class="btn-primary" spinner="save" />
        </x-slot:actions>
    </x-card>
</div>
