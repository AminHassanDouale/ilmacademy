<?php
// resources/views/livewire/teacher/students/index.blade.php

use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableSlot;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Students')] class extends Component {
    use Toast, WithPagination;

    public string $search = '';
    public string $selectedSubject = '';
    public string $selectedCurriculum = '';
    public string $sortBy = 'name';
    public string $sortDirection = 'asc';

    public function mount(): void
    {
        //
    }

    public function getTeacherSubjectsProperty(): Collection
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        return Subject::whereHas('timetableSlots', function($query) use ($teacherProfile) {
            $query->where('teacher_profile_id', $teacherProfile->id);
        })->with('curriculum')->get();
    }

    public function getStudentsProperty()
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return Student::query()->where('id', 0); // Return empty query
        }

        $query = Student::with(['user', 'curriculum'])
            ->whereHas('curriculum.subjects.timetableSlots', function($q) use ($teacherProfile) {
                $q->where('teacher_profile_id', $teacherProfile->id);
            });

        if ($this->search) {
            $query->whereHas('user', function($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            })->orWhere('student_id', 'like', '%' . $this->search . '%');
        }

        if ($this->selectedSubject) {
            $query->whereHas('curriculum.subjects', function($q) {
                $q->where('id', $this->selectedSubject);
            });
        }

        if ($this->selectedCurriculum) {
            $query->where('curriculum_id', $this->selectedCurriculum);
        }

        // Sorting
        if ($this->sortBy === 'name') {
            $query->join('users', 'students.user_id', '=', 'users.id')
                  ->orderBy('users.name', $this->sortDirection)
                  ->select('students.*');
        } else {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        return $query->paginate(12);
    }

    public function getCurriculaProperty(): Collection
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        return \App\Models\Curriculum::whereHas('subjects.timetableSlots', function($query) use ($teacherProfile) {
            $query->where('teacher_profile_id', $teacherProfile->id);
        })->orderBy('name')->get();
    }

    public function sortBy($field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedSubject(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCurriculum(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->selectedSubject = '';
        $this->selectedCurriculum = '';
        $this->resetPage();
    }

    public function getStudentSubjects($studentId): Collection
    {
        $teacherProfile = auth()->user()->teacherProfile;

        if (!$teacherProfile) {
            return collect();
        }

        $student = Student::find($studentId);
        if (!$student) {
            return collect();
        }

        return Subject::whereHas('timetableSlots', function($query) use ($teacherProfile) {
            $query->where('teacher_profile_id', $teacherProfile->id);
        })->where('curriculum_id', $student->curriculum_id)->get();
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Students" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Search -->
                <div class="flex-1 max-w-md">
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search students..."
                        icon="o-magnifying-glass"
                        clearable
                    />
                </div>

                <!-- Subject Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedSubject"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Subjects</option>
                        @foreach($this->teacherSubjects as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Curriculum Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedCurriculum"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Curricula</option>
                        @foreach($this->curricula as $curriculum)
                            <option value="{{ $curriculum->id }}">{{ $curriculum->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-slot:middle>

        <x-slot:actions>
            <x-button
                label="Reset Filters"
                icon="o-x-mark"
                wire:click="resetFilters"
                class="btn-ghost btn-sm"
            />
            <x-button
                label="Export List"
                icon="o-arrow-down-tray"
                class="btn-outline"
            />
        </x-slot:actions>
    </x-header>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-4">
        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Total Students</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->students->total() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">My Subjects</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->teacherSubjects->count() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Curricula</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->curricula->count() }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-orange-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Active Classes</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->teacherSubjects->count() }}</p>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Students Grid -->
    <div class="bg-white rounded-lg shadow">
        <div class="grid grid-cols-1 gap-6 p-6 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @forelse($this->students as $student)
                <div class="p-4 transition-shadow border border-gray-200 rounded-lg hover:shadow-md">
                    <!-- Student Avatar & Info -->
                    <div class="flex items-center mb-4">
                        <div class="flex items-center justify-center w-12 h-12 bg-blue-100 rounded-full">
                            <span class="text-lg font-semibold text-blue-600">
                                {{ strtoupper(substr($student->user->name, 0, 2)) }}
                            </span>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-gray-900 truncate">
                                {{ $student->user->name }}
                            </h3>
                            <p class="text-xs text-gray-500">
                                ID: {{ $student->student_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Student Details -->
                    <div class="space-y-2">
                        <div class="flex items-center text-xs text-gray-600">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            {{ $student->user->email }}
                        </div>

                        @if($student->curriculum)
                            <div class="flex items-center text-xs text-gray-600">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                                {{ $student->curriculum->name }}
                            </div>
                        @endif

                        <div class="flex items-center text-xs text-gray-600">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3c2 0 2 2 0 2L8 9c-2 0-2-2 0-2h0z"></path>
                            </svg>
                            Enrolled: {{ $student->created_at->format('M Y') }}
                        </div>
                    </div>

                    <!-- Subjects with Teacher -->
                    <div class="mt-4">
                        <h4 class="mb-2 text-xs font-medium text-gray-700">My Subjects:</h4>
                        <div class="flex flex-wrap gap-1">
                            @foreach($this->getStudentSubjects($student->id) as $subject)
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium text-blue-800 bg-blue-100 rounded-full">
                                    {{ $subject->code }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex mt-4 space-x-2">
                        <x-button
                            icon="o-eye"
                            tooltip="View Details"
                            class="btn-xs btn-ghost"
                        />
                        <x-button
                            icon="o-chat-bubble-left"
                            tooltip="Contact"
                            class="btn-xs btn-ghost"
                        />
                        <x-button
                            icon="o-document-text"
                            tooltip="Reports"
                            class="btn-xs btn-ghost"
                        />
                    </div>
                </div>
            @empty
                <div class="col-span-full">
                    <div class="py-12 text-center">
                        <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No students found</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            @if($search || $selectedSubject || $selectedCurriculum)
                                Try adjusting your search or filter criteria.
                            @else
                                You don't have any students assigned yet.
                            @endif
                        </p>
                    </div>
                </div>
            @endforelse
        </div>

        <!-- Pagination -->
        @if($this->students->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $this->students->links() }}
            </div>
        @endif
    </div>
</div>
