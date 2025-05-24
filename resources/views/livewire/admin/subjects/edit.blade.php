<?php

use App\Models\Subject;
use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Subject')] class extends Component {
    use Toast;

    // Form state
    public ?Subject $subject = null;
    public string $name = '';
    public string $code = '';
    public ?int $curriculum_id = null;
    public string $level = '';

    public function mount(Subject $subject): void
    {
        $this->subject = $subject;

        // Set form values from subject
        $this->name = $subject->name;
        $this->code = $subject->code;
        $this->curriculum_id = $subject->curriculum_id;
        $this->level = $subject->level;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Accessed edit page for subject: {$subject->name} ({$subject->code})",
            Subject::class,
            $subject->id,
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
            'code' => ['required', 'string', 'max:50', Rule::unique('subjects', 'code')->ignore($this->subject->id)],
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
     * Update the subject
     */
    public function updateSubject(): void
    {
        $this->validate();

        try {
            // Get original values for logging
            $originalName = $this->subject->name;
            $originalCode = $this->subject->code;
            $originalCurriculumId = $this->subject->curriculum_id;
            $originalLevel = $this->subject->level;

            // Get curriculum names for logging
            $oldCurriculum = Curriculum::find($originalCurriculumId);
            $oldCurriculumName = $oldCurriculum ? $oldCurriculum->name : 'Unknown';

            $newCurriculum = Curriculum::find($this->curriculum_id);
            $newCurriculumName = $newCurriculum ? $newCurriculum->name : 'Unknown';

            // Update subject
            $this->subject->update([
                'name' => $this->name,
                'code' => $this->code,
                'curriculum_id' => $this->curriculum_id,
                'level' => $this->level,
            ]);

            // Log activity with details of what changed
            $changes = [];
            if ($originalName !== $this->name) {
                $changes['name'] = [
                    'from' => $originalName,
                    'to' => $this->name
                ];
            }

            if ($originalCode !== $this->code) {
                $changes['code'] = [
                    'from' => $originalCode,
                    'to' => $this->code
                ];
            }

            if ($originalCurriculumId !== $this->curriculum_id) {
                $changes['curriculum'] = [
                    'from' => $oldCurriculumName,
                    'to' => $newCurriculumName
                ];
            }

            if ($originalLevel !== $this->level) {
                $changes['level'] = [
                    'from' => $originalLevel,
                    'to' => $this->level
                ];
            }

            ActivityLog::log(
                Auth::id(),
                'update',
                "Updated subject: {$originalName} ({$originalCode})",
                Subject::class,
                $this->subject->id,
                [
                    'original_name' => $originalName,
                    'original_code' => $originalCode,
                    'original_curriculum' => $oldCurriculumName,
                    'original_level' => $originalLevel,
                    'new_name' => $this->name,
                    'new_code' => $this->code,
                    'new_curriculum' => $newCurriculumName,
                    'new_level' => $this->level,
                    'changes' => $changes
                ]
            );

            // Show success notification
            $this->success("Subject {$this->name} has been updated successfully.");

            // Redirect to the subject details page
            redirect()->route('admin.subjects.show', $this->subject->id);
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
    <x-header title="Edit Subject" separator>
        <x-slot:subtitle>
            {{ $subject->name }} ({{ $subject->code }})
        </x-slot:subtitle>

        <x-slot:actions>
            <x-button
                label="Cancel"
                icon="o-x-mark"
                link="{{ route('admin.subjects.show', $subject->id) }}"
                class="btn-ghost"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Form card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-4">
            <form wire:submit="updateSubject">
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

                    <!-- Stats section -->
                    <div>
                        <h3 class="pb-2 mb-4 text-lg font-medium border-b">Current Statistics</h3>

                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div class="p-3 rounded-lg bg-base-200">
                                <p class="text-xl font-bold">{{ $subject->sessions()->count() }}</p>
                                <p class="text-sm text-gray-500">Sessions</p>
                            </div>

                            <div class="p-3 rounded-lg bg-base-200">
                                <p class="text-xl font-bold">{{ $subject->exams()->count() }}</p>
                                <p class="text-sm text-gray-500">Exams</p>
                            </div>

                            <div class="p-3 rounded-lg bg-base-200">
                                <p class="text-xl font-bold">{{ $subject->subjectEnrollments()->count() }}</p>
                                <p class="text-sm text-gray-500">Enrollments</p>
                            </div>
                        </div>
                    </div>

                    <!-- Form actions -->
                    <div class="flex justify-end mt-6 space-x-2">
                        <x-button
                            label="Cancel"
                            link="{{ route('admin.subjects.show', $subject->id) }}"
                            class="btn-ghost"
                        />
                        <x-button
                            label="Update Subject"
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
