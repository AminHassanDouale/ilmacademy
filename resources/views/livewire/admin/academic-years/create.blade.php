<?php

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Academic Year')] class extends Component {
    use Toast;

    #[Rule('required|string|max:100|unique:academic_years,name')]
    public string $name = '';

    #[Rule('required|date')]
    public string $start_date = '';

    #[Rule('required|date|after:start_date')]
    public string $end_date = '';

    #[Rule('boolean')]
    public bool $is_current = false;

    // Initialize component
    public function mount(): void
    {
        // Set default dates to current and next year
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->addYear()->format('Y-m-d');

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed create academic year page',
            AcademicYear::class,
            null,
            ['ip' => request()->ip()]
        );
    }

    // Save the academic year
    public function save(): void
    {
        $this->validate();

        try {
            DB::beginTransaction();

            // If this is set as current, unset any existing current academic year
            if ($this->is_current) {
                AcademicYear::where('is_current', true)
                    ->update(['is_current' => false]);
            }

            // Create new academic year
            $academicYear = AcademicYear::create([
                'name' => $this->name,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
                'is_current' => $this->is_current,
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created academic year: {$this->name}",
                AcademicYear::class,
                $academicYear->id,
                [
                    'academic_year_name' => $this->name,
                    'start_date' => $this->start_date,
                    'end_date' => $this->end_date,
                    'is_current' => $this->is_current
                ]
            );

            DB::commit();

            // Success message and redirect
            $this->success("Academic year '{$this->name}' has been created successfully.");
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
    <x-header title="Create Academic Year" separator>
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
                @error('name') <x-error>{{ $message }}</x-error> @enderror
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <x-input
                        type="date"
                        label="Start Date"
                        wire:model="start_date"
                        required
                    />
                    @error('start_date') <x-error>{{ $message }}</x-error> @enderror
                </div>
                <div>
                    <x-input
                        type="date"
                        label="End Date"
                        wire:model="end_date"
                        required
                    />
                    @error('end_date') <x-error>{{ $message }}</x-error> @enderror
                </div>
            </div>

            <div>
                <x-checkbox
                    label="Set as current academic year"
                    wire:model="is_current"
                    hint="If checked, this will be set as the current academic year and any previously set current year will be unset."
                />
                @error('is_current') <x-error>{{ $message }}</x-error> @enderror
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" wire:click="cancel" />
            <x-button label="Create Academic Year" icon="o-plus" wire:click="save" class="btn-primary" spinner="save" />
        </x-slot:actions>
    </x-card>
</div>


