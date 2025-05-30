<?php

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ActivityLog;
use App\Models\ChildProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new #[Title('Exam Results')] class extends Component {
    use WithPagination;
    use Toast;

    // Exam to display results for
    public Exam $exam;

    // Filters and search options
    #[Url]
    public string $search = '';

    #[Url]
    public string $sortBy = 'score';

    #[Url]
    public string $sortDirection = 'desc';

    #[Url]
    public int $perPage = 15;

    #[Url]
    public bool $showFilters = false;

    #[Url]
    public string $scoreFilter = '';

    // Component initialization
    public function mount(Exam $exam): void
    {
        $this->exam = $exam;

        // Log access to exam results page
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => 'access',
            'description' => "Viewed results for exam: {$exam->title}",
            'loggable_type' => Exam::class,
            'loggable_id' => $exam->id,
            'ip_address' => request()->ip(),
        ]);
    }

    // Sort data
    public function sortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    // Reset filters
    public function resetFilters(): void
    {
        $this->search = '';
        $this->scoreFilter = '';
        $this->resetPage();
    }

    // Go back to teacher exams
    public function backToExams(): void
    {
        if ($this->exam->teacherProfile) {
            redirect()->route('admin.teachers.exams', $this->exam->teacherProfile);
        } else {
            redirect()->route('admin.exams.index');
        }
    }

    // Export results (placeholder - would need actual export implementation)
    public function exportResults(): void
    {
        $this->info('Export functionality would be implemented here');
    }

    // Add new result (navigate to create form)
    public function addResult(): void
    {
        redirect()->route('admin.exam-results.create', ['exam' => $this->exam->id]);
    }

    // Edit specific result
    public function editResult(int $resultId): void
    {
        redirect()->route('admin.exam-results.edit', $resultId);
    }

    // Delete specific result
    public function deleteResult(int $resultId): void
    {
        try {
            $result = ExamResult::findOrFail($resultId);
            $childName = $result->childProfile ? $result->childProfile->full_name : 'Unknown';

            $result->delete();

            // Log the deletion
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'delete',
                'description' => "Deleted exam result for {$childName} in {$this->exam->title}",
                'loggable_type' => ExamResult::class,
                'loggable_id' => $resultId,
                'ip_address' => request()->ip(),
            ]);

            $this->success("Result for {$childName} has been deleted successfully.");
        } catch (\Exception $e) {
            $this->error("Failed to delete result: {$e->getMessage()}");
        }
    }

    // Get filtered and paginated results
    public function results(): LengthAwarePaginator
    {
        return $this->exam->examResults()
            ->with(['childProfile', 'childProfile.user']) // Eager load relationships
            ->when($this->search, function (Builder $query) {
                $query->whereHas('childProfile', function (Builder $childQuery) {
                    $childQuery->whereHas('user', function (Builder $userQuery) {
                        $userQuery->where('name', 'like', '%' . $this->search . '%');
                    });
                })
                ->orWhere('remarks', 'like', '%' . $this->search . '%');
            })
            ->when($this->scoreFilter, function (Builder $query) {
                switch ($this->scoreFilter) {
                    case 'excellent':
                        $query->where('score', '>=', 90);
                        break;
                    case 'good':
                        $query->whereBetween('score', [80, 89]);
                        break;
                    case 'average':
                        $query->whereBetween('score', [70, 79]);
                        break;
                    case 'below_average':
                        $query->whereBetween('score', [60, 69]);
                        break;
                    case 'poor':
                        $query->where('score', '<', 60);
                        break;
                }
            })
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate($this->perPage);
    }

    // Get exam statistics
    public function getExamStatistics()
    {
        $results = $this->exam->examResults();

        return [
            'total_students' => $results->count(),
            'average_score' => round($results->avg('score'), 2),
            'highest_score' => $results->max('score'),
            'lowest_score' => $results->min('score'),
            'pass_rate' => $results->where('score', '>=', 60)->count() . '/' . $results->count(),
            'grade_distribution' => [
                'A' => $results->where('score', '>=', 90)->count(),
                'B' => $results->whereBetween('score', [80, 89])->count(),
                'C' => $results->whereBetween('score', [70, 79])->count(),
                'D' => $results->whereBetween('score', [60, 69])->count(),
                'F' => $results->where('score', '<', 60)->count(),
            ]
        ];
    }

    // Get score color based on value
    public function getScoreColor(float $score): string
    {
        if ($score >= 90) return 'success';
        if ($score >= 80) return 'info';
        if ($score >= 70) return 'warning';
        if ($score >= 60) return 'secondary';
        return 'error';
    }

    // Get grade based on score
    public function getGrade(float $score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }

    public function with(): array
    {
        return [
            'results' => $this->results(),
            'statistics' => $this->getExamStatistics(),
        ];
    }
};?>

<div>
    <x-header title="Results for: {{ $exam->title }}" separator>
        <x-slot:subtitle>
            <div class="flex flex-wrap gap-2 text-sm">
                <span>{{ $exam->subject ? $exam->subject->name : 'No Subject' }}</span>
                <span>•</span>
                <span>{{ $exam->exam_date ? $exam->exam_date->format('M d, Y') : 'No Date' }}</span>
                <span>•</span>
                <span>{{ ucfirst($exam->type) ?? 'No Type' }}</span>
                @if($exam->teacherProfile && $exam->teacherProfile->user)
                    <span>•</span>
                    <span>Teacher: {{ $exam->teacherProfile->user->name }}</span>
                @endif
            </div>
        </x-slot:subtitle>

        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search students..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable />
        </x-slot:middle>

        <x-slot:actions>
            <div class="flex gap-2">
                <x-button
                    label="Filters"
                    icon="o-funnel"
                    :badge="count(array_filter([$scoreFilter]))"
                    badge-classes="font-mono"
                    @click="$wire.showFilters = true"
                    class="bg-base-300"
                    responsive />

                <x-button
                    label="Export"
                    icon="o-arrow-down-tray"
                    wire:click="exportResults"
                    class="btn-ghost"
                    responsive />

                <x-button
                    label="Add Result"
                    icon="o-plus"
                    wire:click="addResult"
                    class="btn-primary"
                    responsive />

                <x-button
                    label="Back to Exams"
                    icon="o-arrow-left"
                    wire:click="backToExams"
                    class="btn-ghost"
                />
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 gap-4 mb-6 md:grid-cols-3 lg:grid-cols-6">
        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-2xl font-bold">{{ $statistics['total_students'] }}</h3>
                <p class="text-sm opacity-70">Total Students</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-2xl font-bold">{{ $statistics['average_score'] ?? 0 }}%</h3>
                <p class="text-sm opacity-70">Average Score</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-2xl font-bold text-success">{{ $statistics['highest_score'] ?? 0 }}%</h3>
                <p class="text-sm opacity-70">Highest Score</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-2xl font-bold text-error">{{ $statistics['lowest_score'] ?? 0 }}%</h3>
                <p class="text-sm opacity-70">Lowest Score</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <h3 class="text-2xl font-bold">{{ $statistics['pass_rate'] }}</h3>
                <p class="text-sm opacity-70">Pass Rate</p>
            </div>
        </x-card>

        <x-card class="bg-base-100">
            <div class="text-center">
                <div class="flex justify-center gap-1 mb-1">
                    @foreach($statistics['grade_distribution'] as $grade => $count)
                        <div class="text-xs">
                            <div class="font-bold">{{ $grade }}</div>
                            <div>{{ $count }}</div>
                        </div>
                    @endforeach
                </div>
                <p class="text-sm opacity-70">Grade Distribution</p>
            </div>
        </x-card>
    </div>

    <!-- Results Table -->
    <x-card>
        <div class="overflow-x-auto">
            <table class="table w-full table-zebra">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sortBy('childProfile.user.name')">
                            <div class="flex items-center">
                                Student Name
                                @if ($sortBy === 'childProfile.user.name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="cursor-pointer" wire:click="sortBy('score')">
                            <div class="flex items-center">
                                Score
                                @if ($sortBy === 'score')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th>Grade</th>
                        <th>Remarks</th>
                        <th class="cursor-pointer" wire:click="sortBy('created_at')">
                            <div class="flex items-center">
                                Recorded
                                @if ($sortBy === 'created_at')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'o-chevron-up' : 'o-chevron-down' }}" class="w-4 h-4 ml-1" />
                                @endif
                            </div>
                        </th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($results as $result)
                        <tr class="hover">
                            <td>
                                <div class="flex items-center gap-3">
                                    <div class="avatar">
                                        <div class="w-10 h-10 mask mask-squircle">
                                            @if($result->childProfile && $result->childProfile->user)
                                                <img src="{{ $result->childProfile->user->profile_photo_url }}" alt="{{ $result->childProfile->user->name }}">
                                            @else
                                                <div class="flex items-center justify-center w-10 h-10 rounded bg-base-200">
                                                    <x-icon name="o-user" class="w-6 h-6 text-base-content/30" />
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="font-semibold">
                                            {{ $result->childProfile && $result->childProfile->user ? $result->childProfile->user->name : 'Unknown Student' }}
                                        </div>
                                        @if($result->childProfile)
                                            <div class="text-xs opacity-70">
                                                {{ $result->childProfile->full_name }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="text-lg font-bold">{{ $result->score }}%</span>
                                    <div class="radial-progress text-{{ $this->getScoreColor($result->score) }}" style="--value:{{ $result->score }}; --size:2rem;">
                                    </div>
                                </div>
                            </td>
                            <td>
                                <x-badge
                                    label="{{ $this->getGrade($result->score) }}"
                                    color="{{ $this->getScoreColor($result->score) }}"
                                    class="px-3 py-1 text-lg font-bold"
                                />
                            </td>
                            <td>
                                @if($result->remarks)
                                    <div class="max-w-md">
                                        {{ \Illuminate\Support\Str::limit($result->remarks, 100) }}
                                    </div>
                                @else
                                    <span class="text-sm italic text-gray-400">No remarks</span>
                                @endif
                            </td>
                            <td>
                                <div>
                                    {{ $result->created_at->format('M d, Y') }}
                                </div>
                                <div class="text-xs opacity-70">
                                    {{ $result->created_at->format('H:i') }}
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-2">
                                    <x-button
                                        icon="o-pencil"
                                        wire:click="editResult({{ $result->id }})"
                                        color="info"
                                        size="sm"
                                        tooltip="Edit Result"
                                    />

                                    <x-button
                                        icon="o-trash"
                                        wire:click="deleteResult({{ $result->id }})"
                                        color="error"
                                        size="sm"
                                        tooltip="Delete Result"
                                        x-data
                                        x-on:click="
                                            if (confirm('Are you sure you want to delete this result?')) {
                                                $wire.deleteResult({{ $result->id }})
                                            }
                                        "
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="o-chart-bar" class="w-16 h-16 text-gray-400" />
                                    <h3 class="text-lg font-semibold text-gray-600">No results found</h3>
                                    <p class="text-gray-500">Try modifying your search criteria or add some results</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4">
            {{ $results->links() }}
        </div>
    </x-card>

    <!-- Filters Drawer -->
    <x-drawer wire:model="showFilters" title="Result Filters" position="right" class="p-4">
        <div class="flex flex-col gap-4 mb-4">
            <div>
                <x-input
                    label="Search by student name or remarks"
                    wire:model.live.debounce="search"
                    icon="o-magnifying-glass"
                    placeholder="Search..."
                    clearable
                />
            </div>

            <div>
                <x-select
                    label="Filter by score range"
                    placeholder="All scores"
                    :options="[
                        ['label' => 'Excellent (90-100%)', 'value' => 'excellent'],
                        ['label' => 'Good (80-89%)', 'value' => 'good'],
                        ['label' => 'Average (70-79%)', 'value' => 'average'],
                        ['label' => 'Below Average (60-69%)', 'value' => 'below_average'],
                        ['label' => 'Poor (<60%)', 'value' => 'poor']
                    ]"
                    wire:model.live="scoreFilter"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <div>
                <x-select
                    label="Sort by"
                    :options="[
                        ['label' => 'Score (High to Low)', 'value' => 'score_desc'],
                        ['label' => 'Score (Low to High)', 'value' => 'score_asc'],
                        ['label' => 'Student Name (A-Z)', 'value' => 'name_asc'],
                        ['label' => 'Student Name (Z-A)', 'value' => 'name_desc'],
                        ['label' => 'Recently Added', 'value' => 'created_desc'],
                        ['label' => 'Oldest First', 'value' => 'created_asc']
                    ]"
                    option-label="label"
                    option-value="value"
                    wire:change="applySorting($event.target.value)"
                />
            </div>

            <div>
                <x-select
                    label="Items per page"
                    :options="[10, 15, 25, 50]"
                    wire:model.live="perPage"
                />
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Reset" icon="o-x-mark" wire:click="resetFilters" />
            <x-button label="Apply" icon="o-check" wire:click="$set('showFilters', false)" color="primary" />
        </x-slot:actions>
    </x-drawer>
</div>
