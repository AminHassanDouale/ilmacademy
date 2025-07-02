<?php

// Create these files in resources/views/livewire/admin/subject-enrollments/

// 1. CREATE COMPONENT (admin/subject-enrollments/create.php)
use App\Models\SubjectEnrollment;
use App\Models\Subject;
use App\Models\ProgramEnrollment;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Create Subject Enrollment')] class extends Component {
    use Toast;

    public int $program_enrollment_id = 0;
    public int $subject_id = 0;

    public function mount(): void
    {
        //
    }

    public function save(): void
    {
        $this->validate([
            'program_enrollment_id' => 'required|exists:program_enrollments,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        try {
            // Check if enrollment already exists
            $existing = SubjectEnrollment::where('program_enrollment_id', $this->program_enrollment_id)
                ->where('subject_id', $this->subject_id)
                ->first();

            if ($existing) {
                $this->error('Student is already enrolled in this subject.');
                return;
            }

            SubjectEnrollment::create([
                'program_enrollment_id' => $this->program_enrollment_id,
                'subject_id' => $this->subject_id,
            ]);

            $this->success('Subject enrollment created successfully.');
            $this->redirect(route('admin.subject-enrollments.index'));
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

    public function cancel(): void
    {
        $this->redirect(route('admin.subject-enrollments.index'));
    }

    public function with(): array
    {
        return [
            'programEnrollments' => ProgramEnrollment::with(['childProfile', 'academicYear'])->get(),
            'subjects' => Subject::with('curriculum')->get(),
        ];
    }
};?>

<div>
    <x-header title="Create Subject Enrollment" separator>
        <x-slot:actions>
            <x-button label="Cancel" icon="o-x-mark" wire:click="cancel" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <x-card class="max-w-2xl mx-auto">
        <form wire:submit="save">
            <div class="space-y-6">
                <x-select
                    label="Program Enrollment"
                    wire:model="program_enrollment_id"
                    :options="$programEnrollments"
                    option-value="id"
                    option-label="student_name"
                    placeholder="Select a program enrollment"
                    required
                />

                <x-select
                    label="Subject"
                    wire:model="subject_id"
                    :options="$subjects"
                    option-value="id"
                    option-label="name"
                    placeholder="Select a subject"
                    required
                />
            </div>

            <x-slot:actions>
                <x-button label="Cancel" wire:click="cancel" />
                <x-button label="Create Enrollment" icon="o-plus" type="submit" color="primary" />
            </x-slot:actions>
        </form>
    </x-card>
</div>
