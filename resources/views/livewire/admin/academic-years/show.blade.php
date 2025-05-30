<?php

use App\Models\AcademicYear;
use App\Models\ActivityLog;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Academic Year Details')] class extends Component {
    use WithPagination;
    use Toast;

    public AcademicYear $academicYear;
    public int $perPage = 10;
    public string $activeTab = 'enrollments';

    // Initialize component
    public function mount(AcademicYear $academicYear): void
    {
        $this->academicYear = $academicYear;

        // Log activity
        ActivityLog::log(
            Auth::id(),
            'access',
            "Viewed academic year details for {$academicYear->name}",
            AcademicYear::class,
            $academicYear->id,
            ['ip' => request()->ip()]
        );
    }

    // Handle tab switching
    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    // Get program enrollments for this academic year
    public function programEnrollments(): LengthAwarePaginator
    {
        return $this->academicYear->programEnrollments()
            ->with(['childProfile', 'curriculum', 'paymentPlan'])
            ->paginate($this->perPage);
    }

    // Get exams for this academic year
    public function exams(): LengthAwarePaginator
    {
        return $this->academicYear->exams()
            ->with(['subject', 'teacherProfile'])
            ->paginate($this->perPage);
    }

    // Set this academic year as the current one
    public function setAsCurrent(): void
    {
        try {
            // Only proceed if it's not already the current one
            if (!$this->academicYear->is_current) {
                // Update all academic years to not be current
                AcademicYear::where('is_current', true)
                    ->update(['is_current' => false]);

                // Set this one as current
                $this->academicYear->is_current = true;
                $this->academicYear->save();

                // Log the activity
                ActivityLog::log(
                    Auth::id(),
                    'update',
                    "Set {$this->academicYear->name} as the current academic year",
                    AcademicYear::class,
                    $this->academicYear->id,
                    ['set_as_current' => true]
                );

                $this->success("{$this->academicYear->name} has been set as the current academic year.");
            }
        } catch (\Exception $e) {
            $this->error("An error occurred: {$e->getMessage()}");
        }
    }

    public function with(): array
    {
        return [
            'academicYear' => $this->academicYear,
            'enrollmentsCount' => $this->academicYear->programEnrollments()->count(),
            'examsCount' => $this->academicYear->exams()->count(),
            'programEnrollments' => $this->activeTab === 'enrollments' ? $this->programEnrollments() : null,
            'exams' => $this->activeTab === 'exams' ? $this->exams() : null,
        ];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="Academic Year Details: {{ $academicYear->name }}" separator>
        <x-slot:actions>
            <x-button
                label="Back to Academic Years"
                icon="o-arrow-left"
                link="{{ route('admin.academic-years.index') }}"
                class="btn-ghost"
            />

            @if (!$academicYear->is_current)
                <x-button
                    label="Set as Current"
                    icon="o-star"
                    wire:click="setAsCurrent"
                    class="btn-success"
                />
            @endif

            <x-button
                label="Edit"
                icon="o-pencil"
                link="{{ route('admin.academic-years.edit', $academicYear->id) }}"
                class="btn-info"
            />
        </x-slot:actions>
    </x-header>

    <!-- Academic Year Info Card -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card class="lg:col-span-1">
            <div class="p-5">
                <h2 class="mb-4 text-xl font-semibold">Academic Year Information</h2>

                <div class="space-y-4">
                    <div>
                        <span class="block text-sm font-medium text-gray-500">Name</span>
                        <span class="block mt-1 text-lg">{{ $academicYear->name }}</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Start Date</span>
                        <span class="block mt-1">{{ $academicYear->start_date->format('F d, Y') }}</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">End Date</span>
                        <span class="block mt-1">{{ $academicYear->end_date->format('F d, Y') }}</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Duration</span>
                        <span class="block mt-1">{{ $academicYear->start_date->diffInMonths($academicYear->end_date) }} months</span>
                    </div>

                    <div>
                        <span class="block text-sm font-medium text-gray-500">Status</span>
                        <div class="mt-1">
                            @if ($academicYear->is_current)
                                <x-badge label="Current Academic Year" icon="o-check-circle" color="success" />
                            @else
                                <x-badge label="Not Current" icon="o-clock" color="ghost" />
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-card>

        <x-card class="lg:col-span-2">
            <div class="p-5">
                <h2 class="mb-4 text-xl font-semibold">Statistics</h2>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center">
                            <div class="p-3 mr-4 rounded-full bg-primary/20">
                                <x-icon name="o-user-group" class="w-8 h-8 text-primary" />
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">Program Enrollments</h3>
                                <div class="text-2xl font-bold">{{ $enrollmentsCount }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 rounded-lg bg-base-200">
                        <div class="flex items-center">
                            <div class="p-3 mr-4 rounded-full bg-secondary/20">
                                <x-icon name="o-clipboard-document-check" class="w-8 h-8 text-secondary" />
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">Exams</h3>
                                <div class="text-2xl font-bold">{{ $examsCount }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Tabs for related data -->
    <div class="mt-6">
        <div class="mb-4 tabs tabs-boxed">
            <button
                class="tab {{ $activeTab === 'enrollments' ? 'tab-active' : '' }}"
                wire:click="switchTab('enrollments')"
            >
                Program Enrollments ({{ $enrollmentsCount }})
            </button>

            <button
                class="tab {{ $activeTab === 'exams' ? 'tab-active' : '' }}"
                wire:click="switchTab('exams')"
            >
                Exams ({{ $examsCount }})
            </button>
        </div>

        <!-- Tab Content -->
        <x-card>
            @if ($activeTab === 'enrollments')
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Student</th>
                                <th>Curriculum</th>
                                <th>Status</th>
                                <th>Payment Plan</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($programEnrollments as $enrollment)
                                <tr class="hover">
                                    <td>{{ $enrollment->id }}</td>
                                    <td>
                                        <div class="font-bold">
                                            <a href="{{ route('admin.child-profiles.show', $enrollment->childProfile->id) }}" class="link link-hover">
                                                {{ $enrollment->childProfile->full_name ?? 'Unknown student' }}
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.curricula.show', $enrollment->curriculum->id) }}" class="link link-hover">
                                            {{ $enrollment->curriculum->name ?? 'Unknown curriculum' }}
                                        </a>
                                    </td>
                                    <td>
                                        <x-badge
                                            label="{{ $enrollment->status }}"
                                            color="{{ match(strtolower($enrollment->status ?? '')) {
                                                'active' => 'success',
                                                'pending' => 'warning',
                                                'completed' => 'info',
                                                'cancelled' => 'error',
                                                default => 'ghost'
                                            } }}"
                                        />
                                    </td>
                                    <td>
                                        {{ $enrollment->paymentPlan->name ?? 'No payment plan' }}
                                    </td>
                                    <td>
                                        <x-button
                                            icon="o-eye"
                                            link="{{ route('admin.program-enrollments.show', $enrollment->id) }}"
                                            color="secondary"
                                            size="sm"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-4 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <x-icon name="o-information-circle" class="w-12 h-12 text-gray-400" />
                                            <p class="mt-2 text-gray-500">No program enrollments found for this academic year.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($programEnrollments && count($programEnrollments))
                    <div class="p-4 mt-4">
                        {{ $programEnrollments->links() }}
                    </div>
                @endif

            @elseif ($activeTab === 'exams')
                <div class="overflow-x-auto">
                    <table class="table w-full table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Teacher</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($exams as $exam)
                                <tr class="hover">
                                    <td>{{ $exam->id }}</td>
                                    <td>
                                        <div class="font-bold">{{ $exam->title }}</div>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.subjects.show', $exam->subject->id) }}" class="link link-hover">
                                            {{ $exam->subject->name ?? 'Unknown subject' }}
                                        </a>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.teacher-profiles.show', $exam->teacherProfile->id) }}" class="link link-hover">
                                            {{ $exam->teacherProfile->full_name ?? 'Unknown teacher' }}
                                        </a>
                                    </td>
                                    <td>{{ $exam->exam_date->format('M d, Y') }}</td>
                                    <td>
                                        <x-badge
                                            label="{{ $exam->type }}"
                                            color="{{ match(strtolower($exam->type ?? '')) {
                                                'midterm' => 'info',
                                                'final' => 'error',
                                                'quiz' => 'warning',
                                                'assignment' => 'success',
                                                default => 'ghost'
                                            } }}"
                                        />
                                    </td>
                                    <td>
                                        <x-button
                                            icon="o-eye"
                                            link="{{ route('admin.exams.show', $exam->id) }}"
                                            color="secondary"
                                            size="sm"
                                        />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-4 text-center">
                                        <div class="flex flex-col items-center justify-center">
                                            <x-icon name="o-information-circle" class="w-12 h-12 text-gray-400" />
                                            <p class="mt-2 text-gray-500">No exams found for this academic year.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($exams && count($exams))
                    <div class="p-4 mt-4">
                        {{ $exams->links() }}
                    </div>
                @endif
            @endif
        </x-card>
    </div>
</div>
