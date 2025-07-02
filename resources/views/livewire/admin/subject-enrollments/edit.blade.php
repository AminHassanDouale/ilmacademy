<?php

// 3. EDIT COMPONENT (admin/subject-enrollments/edit.php)
use App\Models\SubjectEnrollment;
use App\Models\Subject;
use App\Models\ProgramEnrollment;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('Edit Subject Enrollment')] class extends Component {
    use Toast;

    public SubjectEnrollment $subjectEnrollment;
    public int $program_enrollment_id = 0;
    public int $subject_id = 0;

    public function mount(SubjectEnrollment $subjectEnrollment): void
    {
        $this->subjectEnrollment = $subjectEnrollment;
        $this->program_enrollment_id = $subjectEnrollment->program_enrollment_id;
        $this->subject_id = $subjectEnrollment->subject_id;
    }

    public function update(): void
    {
        $this->validate([
            'program_enrollment_id' => 'required|exists:program_enrollments,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        try {
            $this->subjectEnrollment->update([
                'program_enrollment_id' => $this->program_enrollment_id,
                'subject_id' => $this->subject_id,
            ]);

            $this->success('Subject enrollment updated successfully.');
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
    <x-header title="Edit Subject Enrollment" separator>
        <x-slot:actions>
            <x-button label="Cancel" icon="o-x-mark" wire:click="cancel" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <x-card class="max-w-2xl mx-auto">
        <form wire:submit="update">
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
                <x-button label="Update Enrollment" icon="o-check" type="submit" color="primary" />
            </x-slot:actions>
        </form>
    </x-card>
</div>
