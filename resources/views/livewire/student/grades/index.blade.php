<?php
// resources/views/livewire/student/grades/index.blade.php

use App\Models\Student;
use App\Models\Grade;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('My Grades')] class extends Component {
    use Toast, WithPagination;

    public string $selectedSubject = '';
    public string $selectedSemester = '';
    public string $selectedYear = '';
    public string $gradeType = '';

    public function mount(): void
    {
        $this->selectedYear = now()->year;
    }

    public function getStudentProperty(): ?Student
    {
        return auth()->user()->student;
    }

    public function getGradesProperty()
    {
        $student = $this->student;

        if (!$student) {
            return Grade::query()->where('id', 0); // Return empty query
        }

        $query = Grade::with(['subject', 'student.user'])
            ->where('student_id', $student->id);

        if ($this->selectedSubject) {
            $query->where('subject_id', $this->selectedSubject);
        }

        if ($this->selectedSemester) {
            $query->where('semester', $this->selectedSemester);
        }

        if ($this->selectedYear) {
            $query->whereYear('created_at', $this->selectedYear);
        }

        if ($this->gradeType) {
            $query->where('type', $this->gradeType);
        }

        return $query->orderBy('created_at', 'desc')->paginate(15);
    }

    public function getSubjectsProperty(): Collection
    {
        $student = $this->student;

        if (!$student || !$student->curriculum) {
            return collect();
        }

        return Subject::where('curriculum_id', $student->curriculum_id)
            ->orderBy('name')
            ->get();
    }

    public function getGradeStatsProperty(): array
    {
        $student = $this->student;

        if (!$student) {
            return [
                'overall_gpa' => 0,
                'total_subjects' => 0,
                'current_semester_gpa' => 0,
                'grade_distribution' => []
            ];
        }

        $allGrades = Grade::where('student_id', $student->id)->get();

        $currentSemesterGrades = $allGrades->where('semester', $this->getCurrentSemester())
            ->where('created_at', '>=', now()->startOfYear());

        return [
            'overall_gpa' => $allGrades->avg('points') ?? 0,
            'total_subjects' => $allGrades->pluck('subject_id')->unique()->count(),
            'current_semester_gpa' => $currentSemesterGrades->avg('points') ?? 0,
            'grade_distribution' => $allGrades->groupBy('grade')->map->count()->toArray()
        ];
    }

    public function getCurrentSemester(): string
    {
        $month = now()->month;
        return $month <= 6 ? '1' : '2';
    }

    public function getAvailableYearsProperty(): Collection
    {
        $student = $this->student;

        if (!$student) {
            return collect();
        }

        return Grade::where('student_id', $student->id)
            ->selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year');
    }

    public function getGradeColor(string $grade): string
    {
        return match($grade) {
            'A+', 'A' => 'text-green-600 bg-green-100',
            'A-', 'B+' => 'text-blue-600 bg-blue-100',
            'B', 'B-' => 'text-yellow-600 bg-yellow-100',
            'C+', 'C' => 'text-orange-600 bg-orange-100',
            'C-', 'D' => 'text-red-600 bg-red-100',
            'F' => 'text-red-800 bg-red-200',
            default => 'text-gray-600 bg-gray-100'
        };
    }

    public function resetFilters(): void
    {
        $this->selectedSubject = '';
        $this->selectedSemester = '';
        $this->gradeType = '';
        $this->resetPage();
    }

    public function updatedSelectedSubject(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedSemester(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedYear(): void
    {
        $this->resetPage();
    }

    public function updatedGradeType(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [];
    }
};?>

<div>
    <!-- Page header -->
    <x-header title="My Grades" separator>
        <x-slot:middle>
            <div class="flex items-center space-x-4">
                <!-- Subject Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedSubject"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Subjects</option>
                        @foreach($this->subjects as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Semester Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedSemester"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Semesters</option>
                        <option value="1">Semester 1</option>
                        <option value="2">Semester 2</option>
                    </select>
                </div>

                <!-- Year Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="selectedYear"
                        class="w-full select select-bordered select-sm"
                    >
                        @foreach($this->availableYears as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Grade Type Filter -->
                <div class="flex-1 max-w-xs">
                    <select
                        wire:model.live="gradeType"
                        class="w-full select select-bordered select-sm"
                    >
                        <option value="">All Types</option>
                        <option value="quiz">Quiz</option>
                        <option value="assignment">Assignment</option>
                        <option value="midterm">Midterm</option>
                        <option value="final">Final Exam</option>
                        <option value="project">Project</option>
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
                label="Download Report"
                icon="o-arrow-down-tray"
                class="btn-outline"
            />
        </x-slot:actions>
    </x-header>

    <!-- Grade Statistics -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-4">
        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-blue-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Overall GPA</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($this->gradeStats['overall_gpa'], 2) }}</p>
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
                    <p class="text-sm font-medium text-gray-900">Subjects</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->gradeStats['total_subjects'] }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-purple-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3a1 1 0 011-1h6a1 1 0 011 1v4h3c2 0 2 2 0 2L8 9c-2 0-2-2 0-2h0z"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Current Semester</p>
                    <p class="text-lg font-semibold text-gray-900">{{ number_format($this->gradeStats['current_semester_gpa'], 2) }}</p>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <div class="flex items-center justify-center w-8 h-8 bg-orange-500 rounded-full">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                    </div>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900">Total Grades</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $this->grades->total() }}</p>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Grades Table -->
    <div class="overflow-hidden bg-white rounded-lg shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Subject
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Assessment
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Type
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Grade
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Points
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Date
                        </th>
                        <th class="px-6 py-3 text-xs font-medium tracking-wider text-left text-gray-500 uppercase">
                            Semester
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($this->grades as $grade)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">
                                    {{ $grade->assessment_name ?? 'Assessment' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize {{
                                    match($grade->type) {
                                        'quiz' => 'bg-blue-100 text-blue-800',
                                        'assignment' => 'bg-green-100 text-green-800',
                                        'midterm' => 'bg-yellow-100 text-yellow-800',
                                        'final' => 'bg-red-100 text-red-800',
                                        'project' => 'bg-purple-100 text-purple-800',
                                        default => 'bg-gray-100 text-gray-800'
                                    }
                                }}">
                                    {{ ucfirst($grade->type ?? 'Other') }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold {{ $this->getGradeColor($grade->grade) }}">
                                    {{ $grade->grade }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 whitespace-nowrap">
                                {{ number_format($grade->points, 2) }}/{{ number_format($grade->max_points ?? 100, 2) }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                {{ $grade->created_at->format('M d, Y') }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                Semester {{ $grade->semester ?? 'N/A' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <div class="text-center">
                                    <svg class="w-12 h-12 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                    </svg>
                                    <h3 class="mt-2 text-sm font-medium text-gray-900">No grades found</h3>
                                    <p class="mt-1 text-sm text-gray-500">
                                        @if($selectedSubject || $selectedSemester || $gradeType)
                                            Try adjusting your filter criteria.
                                        @else
                                            Your grades will appear here once they are entered by your teachers.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        @if($this->grades->hasPages())
            <div class="px-6 py-4 border-t border-gray-200">
                {{ $this->grades->links() }}
            </div>
        @endif
    </div>

    <!-- Grade Distribution Chart (if grades exist) -->
    @if($this->grades->count() > 0 && !empty($this->gradeStats['grade_distribution']))
        <div class="mt-6 bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Grade Distribution</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-7">
                    @foreach(['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D', 'F'] as $gradeLevel)
                        @php
                            $count = $this->gradeStats['grade_distribution'][$gradeLevel] ?? 0;
                            $percentage = $this->grades->total() > 0 ? ($count / $this->grades->total()) * 100 : 0;
                        @endphp
                        <div class="text-center">
                            <div class="text-2xl font-bold {{ $this->getGradeColor($gradeLevel) }} px-3 py-2 rounded-lg">
                                {{ $gradeLevel }}
                            </div>
                            <div class="mt-1 text-sm text-gray-600">{{ $count }}</div>
                            <div class="text-xs text-gray-500">{{ number_format($percentage, 1) }}%</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
