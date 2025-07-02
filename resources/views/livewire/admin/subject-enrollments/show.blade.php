<?php

// 2. SHOW COMPONENT (admin/subject-enrollments/show.php)
use App\Models\SubjectEnrollment;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('View Subject Enrollment')] class extends Component {
    public SubjectEnrollment $subjectEnrollment;

    public function mount(SubjectEnrollment $subjectEnrollment): void
    {
        $this->subjectEnrollment = $subjectEnrollment->load([
            'programEnrollment.childProfile',
            'programEnrollment.academicYear',
            'subject.curriculum'
        ]);
    }

    public function goBack(): void
    {
        $this->redirect(route('admin.subject-enrollments.index'));
    }

    public function editEnrollment(): void
    {
        $this->redirect(route('admin.subject-enrollments.edit', $this->subjectEnrollment));
    }
};?>

<div>
    <x-header title="Subject Enrollment Details" separator>
        <x-slot:actions>
            <x-button label="Back" icon="o-arrow-left" wire:click="goBack" class="btn-ghost" />
            <x-button label="Edit" icon="o-pencil" wire:click="editEnrollment" class="btn-primary" />
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-card title="Student Information">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Name</label>
                    <p class="text-lg">{{ $subjectEnrollment->programEnrollment->childProfile->full_name ?? 'Unknown' }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Email</label>
                    <p>{{ $subjectEnrollment->programEnrollment->childProfile->email ?? 'N/A' }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Academic Year</label>
                    <p>{{ $subjectEnrollment->programEnrollment->academicYear->name ?? 'N/A' }}</p>
                </div>
            </div>
        </x-card>

        <x-card title="Subject Information">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Subject</label>
                    <p class="text-lg">{{ $subjectEnrollment->subject->name ?? 'Unknown' }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Code</label>
                    <p>{{ $subjectEnrollment->subject->code ?? 'N/A' }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Curriculum</label>
                    <p>{{ $subjectEnrollment->subject->curriculum->name ?? 'N/A' }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Enrollment Date</label>
                    <p>{{ $subjectEnrollment->created_at->format('M d, Y H:i') }}</p>
                </div>
            </div>
        </x-card>
    </div>
</div>
