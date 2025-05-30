<?php

use App\Models\Subject;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Subject')] class extends Component {
    use Toast;

    // Form state
    public string $name = '';
    public string $code = '';
    public ?int $curriculum_id = null;
    public string $level = '';

    // Prepopulated curriculum_id when passed from URL
    public function mount(): void
    {
        // Check if curriculum_id is provided in the query string
        if (request()->has('curriculum_id')) {
            $this->curriculum_id = (int) request()->get('curriculum_id');
        }

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            'Accessed subject creation page',
            Subject::class,
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
            'code' => ['required', 'string', 'max:50', Rule::unique('subjects', 'code')],
            'curriculum_id' => ['required', 'exists:curricula,id'],
            'level' => ['required', 'string'],
        ];
    }

    /**
     * Custom error messages
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'The subject name is required.',
            'code.required' => 'The subject code is required.',
            'code.unique' => 'This subject code is already in use.',
            'curriculum_id.required' => 'Please select a curriculum.',
            'curriculum_id.exists' => 'The selected curriculum does not exist.',
            'level.required' => 'Please select a level for this subject.',
        ];
    }

    /**
     * Get curricula for dropdown
     */
    public function curricula()
    {
        return Curriculum::orderBy('name')->get();
    }

    /**
     * Get available levels for dropdown
     */
    public function levels()
    {
        return [
            'Beginner' => 'Beginner',
            'Intermediate' => 'Intermediate',
            'Advanced' => 'Advanced',
        ];
    }

    /**
     * Create a new subject
     */
    public function createSubject(): void
    {
        $this->validate();

        try {
            // Create subject
            $subject = Subject::create([
                'name' => $this->name,
                'code' => $this->code,
                'curriculum_id' => $this->curriculum_id,
                'level' => $this->level,
            ]);

            // Get curriculum name for log
            $curriculum = Curriculum::find($this->curriculum_id);
            $curriculumName = $curriculum ? $curriculum->name : 'Unknown';

            // Log activity
            ActivityLog::log(
                Auth::id(),
                'create',
                "Created new subject: {$this->name} ({$this->code}) for curriculum: {$curriculumName}",
                Subject::class,
                $subject->id,
                [
                    'subject_name' => $this->name,
                    'subject_code' => $this->code,
                    'curriculum_id' => $this->curriculum_id,
                    'curriculum_name' => $curriculumName,
                    'level' => $this->level
                ]
            );

            // Show success notification
            $this->success("Subject {$this->name} has been created successfully.");

            // Reset form
            $this->reset(['name', 'code', 'level']);

            // Redirect to the subjects index or subject details page
            redirect()->route('admin.subjects.show', $subject->id);
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    public function with(): array
    {
        return [
            'curricula' => $this->curricula(),
            'levels' => $this->levels(),
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Create New Subject" separator>
        <x-slot:actions>
            <x-button
                label="Back to Subjects"
                icon="o-arrow-left"
                link="{{ route('admin.subjects.index') }}"
                class="btn-ghost"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Form card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-4">
            <form wire:submit="createSubject">
                <div class="grid grid-cols-1 gap-6">
                    <!-- Basic information section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Basic Information</h3>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- Name field -->
                            <div class="md:col-span-2">
                                <x-input
                                    label="Subject Name"
                                    wire:model="name"
                                    placeholder="Enter subject name"
                                    hint="e.g., Algebra, Chemistry, English Literature"
                                    required
                                />
                            </div>

                            <!-- Code field -->
                            <div>
                                <x-input
                                    label="Subject Code"
                                    wire:model="code"
                                    placeholder="Enter unique code"
                                    hint="e.g., ALG-101, CHEM-201, ENG-301"
                                    required
                                />
                            </div>

                            <!-- Level field -->
                            <div>
                                <x-select
                                    label="Subject Level"
                                    placeholder="Select level"
                                    :options="$levels"
                                    wire:model="level"
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Curriculum section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Curriculum Assignment</h3>

                        <div>
                            <x-select
                                label="Curriculum"
                                placeholder="Select curriculum"
                                :options="$curricula"
                                wire:model="curriculum_id"
                                option-label="name"
                                option-value="id"
                                option-description="code"
                                hint="Select the curriculum this subject belongs to"
                                required
                            />
                        </div>
                    </div>

                    <!-- Form actions -->
                    <div class="flex justify-end mt-6 space-x-2">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.subjects.index') }}"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Create Subject"
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
