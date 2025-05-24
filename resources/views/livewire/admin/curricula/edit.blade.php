<?php

use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Curriculum')] class extends Component {
    use Toast;

    // Form state
    public ?Curriculum $curriculum = null;
    public string $name = '';
    public string $code = '';
    public string $description = '';

    public function mount(Curriculum $curriculum): void
    {
        $this->curriculum = $curriculum;

        // Set form values from curriculum
        $this->name = $curriculum->name;
        $this->code = $curriculum->code;
        $this->description = $curriculum->description ?? '';

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for curriculum: {$curriculum->name} ({$curriculum->code})",
            Curriculum::class,
            $curriculum->id,
            ['ip' => request()->ip()]
        );
    }

    /**
     * Form validation rules
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('curricula', 'code')->ignore($this->curriculum->id)],
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom error messages
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'The curriculum name is required.',
            'code.required' => 'The curriculum code is required.',
            'code.unique' => 'This curriculum code is already in use.',
        ];
    }

    /**
     * Update the curriculum
     */
    public function updateCurriculum(): void
    {
        $this->validate();

        try {
            // Get original values for logging
            $originalName = $this->curriculum->name;
            $originalCode = $this->curriculum->code;

            // Update curriculum
            $this->curriculum->update([
                'name' => $this->name,
                'code' => $this->code,
                'description' => $this->description,
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'update',
                "Updated curriculum: {$originalName} ({$originalCode}) to {$this->name} ({$this->code})",
                Curriculum::class,
                $this->curriculum->id,
                [
                    'original_name' => $originalName,
                    'original_code' => $originalCode,
                    'new_name' => $this->name,
                    'new_code' => $this->code
                ]
            );

            // Show success notification
            $this->success("Curriculum {$this->name} has been updated successfully.");

            // Redirect to the curriculum details page
            redirect()->route('admin.curricula.show', $this->curriculum->id);
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Edit Curriculum" separator>
        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-x-mark"
                link="{{ route('admin.curricula.show', $curriculum->id) }}"
                class="btn-ghost"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Form card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-4">
            <form wire:submit="updateCurriculum">
                <div class="grid grid-cols-1 gap-6">
                    <!-- Basic information section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Basic Information</h3>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- Name field -->
                            <div class="md:col-span-2">
                                <x-input
                                    label="Curriculum Name"
                                    wire:model="name"
                                    placeholder="Enter curriculum name"
                                    hint="e.g., General Science, Mathematics, Computer Science"
                                    required
                                />
                            </div>

                            <!-- Code field -->
                            <div>
                                <x-input
                                    label="Curriculum Code"
                                    wire:model="code"
                                    placeholder="Enter unique code"
                                    hint="e.g., GS-2025, MATH-21, CS-101"
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Description section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Description</h3>

                        <div>
                            <x-textarea
                                label="Curriculum Description"
                                wire:model="description"
                                placeholder="Enter curriculum description"
                                hint="Provide details about the curriculum's objectives, outcomes, and structure"
                                rows="6"
                            />
                        </div>
                    </div>

                    <!-- Stats section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Current Statistics</h3>

                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="p-3 rounded-lg bg-base-200">
                                <p class="text-xl font-bold">{{ $curriculum->subjects()->count() }}</p>
                                <p class="text-sm text-gray-500">Subjects</p>
                            </div>

                            <div class="p-3 rounded-lg bg-base-200">
                                <p class="text-xl font-bold">{{ $curriculum->programEnrollments()->count() }}</p>
                                <p class="text-sm text-gray-500">Enrollments</p>
                            </div>

                            <div class="p-3 rounded-lg bg-base-200">
                                <p class="text-xl font-bold">{{ $curriculum->paymentPlans()->count() }}</p>
                                <p class="text-sm text-gray-500">Payment Plans</p>
                            </div>
                        </div>
                    </div>

                    <!-- Form actions -->
                    <div class="flex justify-end mt-6 space-x-2">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.curricula.show', $curriculum->id) }}"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Update Curriculum"
                            icon="o-check"
                            type="submit"
                            color="primary"
                            spinner
                        />
                    </div>
                </div>
            </form>
        </div>
    </x-card>
</div>
