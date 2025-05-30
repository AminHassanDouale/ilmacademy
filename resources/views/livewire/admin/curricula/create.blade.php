<?php

use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Curriculum')] class extends Component {
    use Toast;

    // Form state
    public string $name = '';
    public string $code = '';
    public string $description = '';

    public function mount(): void
    {
        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed curriculum creation page',
            Curriculum::class,
            null,
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
            'code' => ['required', 'string', 'max:50', Rule::unique('curricula', 'code')],
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
     * Create a new curriculum
     */
    public function createCurriculum(): void
    {
        $this->validate();

        try {
            // Create curriculum
            $curriculum = Curriculum::create([
                'name' => $this->name,
                'code' => $this->code,
                'description' => $this->description,
            ]);

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created new curriculum: {$this->name} ({$this->code})",
                Curriculum::class,
                $curriculum->id,
                [
                    'curriculum_name' => $this->name,
                    'curriculum_code' => $this->code
                ]
            );

            // Show success notification
            $this->success("Curriculum {$this->name} has been created successfully.");

            // Reset form
            $this->reset(['name', 'code', 'description']);

            // Redirect to the curricula index page
            redirect()->route('admin.curricula.index');
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create New Curriculum" separator>
        <x-slot:actions>
            <x-button
                label="Back to Curricula"
                icon="o-arrow-left"
                link="/admin/curricula"
                class="btn-ghost"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Form card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-4">
            <form wire:submit="createCurriculum">
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

                    <!-- Form actions -->
                    <div class="flex justify-end mt-6 space-x-2">
                        <x-button
                            label="Cancel"
                            link="/admin/curricula"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Create Curriculum"
                            icon="o-plus"
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
