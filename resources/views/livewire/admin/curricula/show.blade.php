<?php

use App\Models\Curriculum;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new #[Title('View Curriculum')] class extends Component {
    use Toast;

    public ?Curriculum $curriculum = null;

    public function mount(Curriculum $curriculum): void
    {
        // Load curriculum with relationships
        $this->curriculum = $curriculum->load(['subjects', 'programEnrollments', 'paymentPlans'])
            ->loadCount(['subjects', 'programEnrollments', 'paymentPlans']);

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Viewed curriculum: {$this->curriculum->name} ({$this->curriculum->code})",
            Curriculum::class,
            $this->curriculum->id,
            ['ip' => request()->ip()]
        );
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Curriculum Details" separator>
        <x-slot:actions>
            <x-button
                label="Back to Curricula"
                icon="o-arrow-left"
                link="/admin/curricula"
                class="btn-ghost"
                responsive
            />

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.curricula.edit', $curriculum->id) }}"
                class="btn-info"
                responsive
            />
        </x-slot:actions>
    </x-header>

    <!-- Curriculum details card -->
    <x-card class="max-w-4xl mx-auto">
        <div class="p-4">
            <div class="grid grid-cols-1 gap-6">
                <!-- Basic information section -->
                <div>
                    <h3 class="pb-2 mb-4 text-lg font-medium border-b">Basic Information</h3>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- Name field -->
                        <div>
                            <label class="text-sm font-medium text-gray-500">Curriculum Name</label>
                            <p class="mt-1 font-semibold">{{ $curriculum->name }}</p>
                        </div>

                        <!-- Code field -->
                        <div>
                            <label class="text-sm font-medium text-gray-500">Curriculum Code</label>
                            <p class="mt-1">
                                <x-badge label="{{ $curriculum->code }}" color="info" />
                            </p>
                        </div>

                        <!-- Created date -->
                        <div>
                            <label class="text-sm font-medium text-gray-500">Created</label>
                            <p class="mt-1">{{ $curriculum->created_at->format('M d, Y') }}</p>
                        </div>

                        <!-- Updated date -->
                        <div>
                            <label class="text-sm font-medium text-gray-500">Last Updated</label>
                            <p class="mt-1">{{ $curriculum->updated_at->format('M d, Y') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Description section -->
                <div>
                    <h3 class="pb-2 mb-4 text-lg font-medium border-b">Description</h3>

                    <div>
                        <div class="p-4 rounded-lg bg-base-200">
                            @if($curriculum->description)
                                <p class="whitespace-pre-line">{{ $curriculum->description }}</p>
                            @else
                                <p class="italic text-gray-500">No description provided.</p>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Stats section -->
                <div>
                    <h3 class="pb-2 mb-4 text-lg font-medium border-b">Statistics</h3>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <!-- Subjects count -->
                        <div class="p-4 text-center rounded-lg bg-base-200">
                            <p class="text-2xl font-bold">{{ $curriculum->subjects_count }}</p>
                            <p class="text-gray-500">Subjects</p>
                            <x-button
                                label="View Subjects"
                                icon="o-book-open"
                                link="{{ route('admin.subjects.index', ['curriculum' => $curriculum->id]) }}"
                                class="mt-2 btn-sm"
                                responsive
                            />
                        </div>

                        <!-- Enrollments count -->
                        <div class="p-4 text-center rounded-lg bg-base-200">
                            <p class="text-2xl font-bold">{{ $curriculum->program_enrollments_count }}</p>
                            <p class="text-gray-500">Enrollments</p>
                            <x-button
                                label="View Enrollments"
                                icon="o-user-group"
                                link="{{ route('admin.enrollments.index', ['curriculum' => $curriculum->id]) }}"
                                class="mt-2 btn-sm"
                                responsive
                            />
                        </div>

                        <!-- Payment plans count -->
                        <div class="p-4 text-center rounded-lg bg-base-200">
                            <p class="text-2xl font-bold">{{ $curriculum->payment_plans_count }}</p>
                            <p class="text-gray-500">Payment Plans</p>
                            <x-button
                                label="Manage Plans"
                                icon="o-banknotes"
                                link="{{ route('admin.payment-plans.index', ['curriculum' => $curriculum->id]) }}"
                                class="mt-2 btn-sm"
                                responsive
                            />
                        </div>
                    </div>
                </div>

                <!-- Actions section -->
                <div class="flex justify-between pt-4 border-t">
                    <x-button
                        label="Back to List"
                        icon="o-arrow-left"
                        link="/admin/curricula"
                        class="btn-ghost"
                    />

                    <div class="space-x-2">
                        <x-button
                            label="Edit Curriculum"
                            icon="o-pencil"
                            link="{{ route('admin.curricula.edit', $curriculum->id) }}"
                            class="btn-info"
                        />

                        <x-button
                            label="Add Subject"
                            icon="o-plus"
                            link="{{ route('admin.subjects.create', ['curriculum_id' => $curriculum->id]) }}"
                            class="btn-primary"
                        />
                    </div>
                </div>
            </div>
        </div>
    </x-card>
</div>
